<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\BoundedConnectionPool;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\PoolIsolationKey;
use Infocyph\Runwire\Network\TlsClientOptions;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Explicit tenant/origin-scoped H1 keep-alive session, backed by a bounded
 * pool. Never pools H2/H3 streams or silently downgrades QUIC/ALPN.
 */
final class PooledHttp1Session
{
    private readonly BoundedConnectionPool $pool;

    private readonly PoolIsolationKey $key;

    private readonly DialOptions $options;

    private readonly string $host;

    private readonly string $scheme;

    private readonly int $port;

    private bool $closed = false;

    /** @var array<int,OutboundHttpHandle> */
    private array $inFlight = [];

    /**
     * Construct one immutable connection trust domain, shared by H1 requests.
     * @param string $scope Opaque worker/tenant identifier; do not reuse across tenants.
     * @param string|null $tlsPolicyIdentity Revisioned certificate/trust policy for HTTPS.
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly HappyEyeballsDialer $dialer,
        string $origin,
        string $scope,
        ?DialOptions $options = null,
        ?string $tlsPolicyIdentity = null,
        int $maxConnections = 8,
    ) {
        $parts = parse_url($origin);
        if (!is_array($parts) || !is_string($parts['host'] ?? null)
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user'], $parts['pass']) || isset($parts['query'])
            || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new InvalidArgumentException('H1 session requires an absolute credential-free HTTP origin.');
        }
        $this->scheme = strtolower($parts['scheme']);
        $this->host = strtolower($parts['host']);
        $this->port = $parts['port'] ?? ($this->scheme === 'https' ? 443 : 80);
        $endpoint = DialEndpoint::parse('tcp://' . $this->host . ':' . $this->port);
        $options ??= new DialOptions();
        if ($options->cancellation !== null || $options->deadline !== null) {
            throw new InvalidArgumentException('H1 session deadlines and cancellation belong to each request.');
        }
        $secure = $this->scheme === 'https';
        if ($secure && $tlsPolicyIdentity === null) {
            throw new InvalidArgumentException('HTTPS session pooling needs an explicit revisioned TLS policy identity.');
        }
        if (!$secure && ($options->tls !== null || $tlsPolicyIdentity !== null)) {
            throw new InvalidArgumentException('Plaintext session cannot hold a TLS trust policy.');
        }
        $tls = $secure ? ($options->tls ?? new TlsClientOptions($this->host, alpnProtocols: ['http/1.1'])) : null;
        if ($tls !== null && (strcasecmp($tls->peerName, $this->host) !== 0
            || $tls->alpnProtocols !== ['http/1.1'])) {
            throw new InvalidArgumentException('Session TLS must verify the exact original peer and H1 ALPN.');
        }
        $this->options = new DialOptions(
            $options->connectTimeoutSeconds, connectionLimits: $options->connectionLimits,
            tcpNoDelay: $options->tcpNoDelay, tls: $tls, addressPolicy: $options->addressPolicy,
        );
        $this->key = new PoolIsolationKey(
            $endpoint, $scope, $tls, $tlsPolicyIdentity, $secure ? 'http/1.1' : null,
        );
        $this->pool = new BoundedConnectionPool($loop, maxActive: $maxConnections);
    }

    /**
     * Send a same-origin H1 request and return its independently cancellable owner.
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Throwable):void $completion
     */
    public function send(
        ClientRequest $request,
        callable $onHeaders,
        callable $onBody,
        callable $completion,
    ): OutboundHttpHandle {
        if ($this->closed) {
            throw new LogicException('H1 session has been closed.');
        }
        $this->verifyRequest($request);
        $source = $request->cancellation === null
            ? new CancellationSource($request->deadline)
            : CancellationSource::linked($request->cancellation, $request->deadline);
        $handle = new OutboundHttpHandle($source);
        $owned = new ClientRequest(
            $request->method, $request->uri, $request->headers, $request->body,
            $request->protocol, $source->token(), $request->deadline,
        );
        $this->pool->acquire($this->key, function (int $lease, ?Connection $connection, ?Throwable $error) use (
            $owned, $handle, $onHeaders, $onBody, $completion,
        ): void {
            $this->inFlight[$lease] = $handle;
            $finish = function (?Connection $idle, ?Throwable $failure) use ($lease, $handle, $completion): void {
                unset($this->inFlight[$lease]);
                $this->pool->release($lease, $idle);
                $handle->complete();
                $completion($failure);
            };
            if ($error !== null || $owned->cancellation?->isCancelled()) {
                $finish(null, $error ?? new CancelledException(CancellationReason::HOST_CANCELLED));

                return;
            }
            try {
                if ($connection !== null) {
                    (new Http1ConnectedExchange(
                        $this->loop, $connection, $owned, $onHeaders, $onBody, $finish,
                    ))->start();

                    return;
                }
                Http1OutboundClient::connect(
                    $this->loop, $this->dialer, $owned, $onHeaders, $onBody, $finish,
                    new DialOptions(
                        $this->options->connectTimeoutSeconds,
                        $owned->deadline,
                        $owned->cancellation,
                        $this->options->connectionLimits,
                        $this->options->tcpNoDelay,
                        $this->options->tls,
                        $this->options->addressPolicy,
                    ),
                );
            } catch (Throwable $failure) {
                $connection?->abort();
                $finish(null, $failure);
            }
        });

        return $handle;
    }

    /** Close idle connections and cancel active work; remaining callbacks settle once. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->pool->close();
        foreach ($this->inFlight as $handle) {
            $handle->cancel();
        }
    }

    /** Bounded pool counters, including actual healthy keep-alive reuse. */
    public function metrics(): array
    {
        return $this->pool->metrics();
    }

    private function verifyRequest(ClientRequest $request): void
    {
        $parts = parse_url($request->uri);
        if (!is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== $this->scheme
            || strtolower($parts['host'] ?? '') !== $this->host
            || ($parts['port'] ?? ($this->scheme === 'https' ? 443 : 80)) !== $this->port
            || $request->protocol->preferred !== ProtocolVersion::HTTP_1_1
            || $request->protocol->allowedVersions !== [ProtocolVersion::HTTP_1_1]) {
            throw new InvalidArgumentException('Pooled H1 request must preserve exact origin and strict H1 protocol policy.');
        }
    }
}
