<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Enum\DialAttemptPhase;
use Infocyph\Runwire\Network\Internal\ClientTlsHandshake;
use Infocyph\Runwire\Network\Internal\DialAttemptLifecycle;
use Infocyph\Runwire\Network\Internal\VerifiedOutboundTlsPeers;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use RuntimeException;
use Throwable;

/**
 * Nonblocking numeric-TCP and Unix outgoing connector with exactly-once completion.
 *
 * Hostname resolution must be supplied by a future off-loop DNS stage; this
 * connector deliberately never falls back to synchronous system DNS.
 */
final class Dialer
{
    private bool $closed = false;

    private int $nextId = 1;

    private readonly int $ownerPid;

    /**
     * @var array<int, array{
     *   stream:resource, state:DialAttemptLifecycle,
     *   callback:Closure, options:DialOptions, tcp:bool, candidateIp:?string,
     *   watcher:?int, timer:?int, subscription:?CancellationSubscription,
     *   tls:?ClientTlsHandshake
     * }>
     */
    private array $pending = [];

    /** Create a loop-bound outgoing connector with a strict pending socket limit. */
    public function __construct(private readonly LoopInterface $loop, private readonly int $maxPending = 64)
    {
        if ($maxPending < 1 || $maxPending > 65_536) {
            throw new InvalidArgumentException('Maximum pending dial count must be 1–65536.');
        }
        $pid = getmypid();
        if (!is_int($pid)) {
            throw new RuntimeException('Unable to establish outbound dial process ownership.');
        }
        $this->ownerPid = $pid;
    }

    /** Cancel one pending dial without invoking its completion twice. */
    public function cancel(int $id): bool
    {
        $this->assertProcessOwner();
        if (!array_key_exists($id, $this->pending)) {
            return false;
        }
        $this->finish($id, null, new CancelledException(CancellationReason::HOST_CANCELLED));

        return true;
    }

    /** Close all pending sockets and reject future attempts. */
    public function close(): void
    {
        $this->assertProcessOwner();
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $error = null;
        foreach (array_keys($this->pending) as $id) {
            try {
                $this->cancel($id);
            } catch (Throwable $failure) {
                $error ??= $failure;
            }
        }
        if ($error !== null) {
            throw $error;
        }
    }

    /**
     * Launch a numeric TCP or Unix-domain stream dial without blocking the loop.
     *
     * @param callable(?Connection, ?Throwable):void $completion
     */
    public function connect(
        DialEndpoint|string $endpoint,
        callable $completion,
        ?DialOptions $options = null,
    ): int {
        if ($this->closed) {
            throw new LogicException('Dialer is closed.');
        }
        if (count($this->pending) >= $this->maxPending) {
            throw new OverflowException('Concurrent dial capacity exhausted.');
        }
        $endpoint = is_string($endpoint) ? DialEndpoint::parse($endpoint) : $endpoint;
        if ($endpoint->scheme === 'tcp' && !$endpoint->isIpLiteral) {
            throw new InvalidArgumentException('Hostname dialing requires a nonblocking DNS resolver; synchronous DNS is forbidden.');
        }
        if (!function_exists('socket_import_stream') || !function_exists('socket_get_option')) {
            throw new RuntimeException('Nonblocking connection verification requires the optional sockets extension.');
        }
        $options ??= new DialOptions();
        if ($options->tls !== null && $endpoint->scheme !== 'tcp') {
            throw new InvalidArgumentException('Client TLS is supported only over TCP, not Unix-domain sockets.');
        }
        self::assertAddressPolicy($endpoint, $options);
        $stream = $this->open($endpoint);
        $state = new DialAttemptLifecycle();
        $state->transition(DialAttemptPhase::CONNECTING);
        if ($this->nextId === PHP_INT_MAX) {
            fclose($stream);

            throw new OverflowException('Dial identifiers exhausted.');
        }

        $id = $this->nextId++;
        $this->pending[$id] = [
            'stream' => $stream,
            'state' => $state,
            'callback' => Closure::fromCallable($completion),
            'options' => $options,
            'tcp' => $endpoint->scheme === 'tcp',
            'candidateIp' => $endpoint->host,
            'watcher' => null,
            'timer' => null,
            'subscription' => null,
            'tls' => null,
        ];

        try {
            $this->pending[$id]['watcher'] = $this->loop->onWritable($stream, function () use ($id): void {
                $this->ready($id);
            });
            $this->attachDeadline($id, $options);
            $this->attachCancellation($id, $options);
        } catch (Throwable $error) {
            $this->release($id);

            throw $error;
        }

        return $id;
    }

    /** Number of pending, not-yet-handed-off outgoing sockets. */
    public function pendingCount(): int
    {
        $this->assertProcessOwner();
        $this->assertProcessOwner();
        return count($this->pending);
    }

    /** Validate a restricted destination before opening its socket. */
    private static function assertAddressPolicy(DialEndpoint $endpoint, DialOptions $options): void
    {
        if ($options->addressPolicy === null) {
            return;
        }
        if ($endpoint->scheme !== 'tcp') {
            throw new InvalidArgumentException('IP-based outbound address policy requires TCP.');
        }

        $options->addressPolicy->assertCandidate($endpoint->host ?? '');
    }

    private function assertProcessOwner(): void
    {
        if (getmypid() !== $this->ownerPid) {
            throw new LogicException('Outbound dial ownership cannot cross a fork; create a new dialer in the worker.');
        }
    }

    private function attachCancellation(int $id, DialOptions $options): void
    {
        $token = $options->cancellation;
        if ($token === null || !array_key_exists($id, $this->pending)) {
            return;
        }
        $subscription = $token->onCancel(function () use ($id): void {
            $this->cancel($id);
        });
        if (array_key_exists($id, $this->pending)) {
            $this->pending[$id]['subscription'] = $subscription;
        } else {
            $subscription->unsubscribe();
        }
    }

    private function attachDeadline(int $id, DialOptions $options): void
    {
        $delay = $options->connectTimeoutSeconds;
        foreach ([$options->deadline, $options->cancellation?->deadline()] as $deadline) {
            $remaining = $deadline?->remainingSeconds();
            if ($remaining !== null) {
                $delay = min($delay, $remaining);
            }
        }
        $this->pending[$id]['timer'] = $this->loop->delay(max(0, $delay), function () use ($id): void {
            $this->finish($id, null, new RuntimeException('Connection attempt deadline expired.'));
        });
    }

    /**
     * @param resource $stream
     */
    private function checkConnected(mixed $stream): void
    {
        $socket = socket_import_stream($stream);
        if ($socket === false) {
            throw new RuntimeException('Could not inspect outgoing socket state.');
        }
        $error = socket_get_option($socket, SOL_SOCKET, SO_ERROR);
        if (!is_int($error)) {
            throw new RuntimeException('Could not verify outgoing connection status.');
        }
        if ($error !== 0) {
            throw new RuntimeException('Outgoing connection failed: ' . socket_strerror($error));
        }
    }

    private function finish(int $id, ?Connection $connection, ?Throwable $error): void
    {
        $job = $this->pending[$id] ?? null;
        if ($job === null) {
            return;
        }
        $this->release($id, $connection !== null, $error instanceof CancelledException);
        if ($error !== null) {
            ($job['callback'])(null, $error);

            return;
        }
        ($job['callback'])($connection, null);
    }

    private function handoff(int $id, ?string $protocol): void
    {
        $job = $this->pending[$id] ?? null;
        if ($job === null) {
            return;
        }

        try {
            $job['state']->transition(DialAttemptPhase::READY);
            $connection = ConnectionFactory::dialed(
                $job['state'],
                $this->loop,
                $job['stream'],
                limits: $job['options']->connectionLimits ?? new ConnectionLimits(),
                negotiatedProtocol: $protocol,
                encrypted: $job['options']->tls !== null,
                tcpTransport: $job['tcp'],
                tcpNoDelayOverride: $job['options']->tcpNoDelay,
                strictTlsPeerEof: $job['options']->tls !== null,
            );
            if ($job['options']->tls !== null) {
                VerifiedOutboundTlsPeers::register($connection, $job['options']->tls->peerName);
            }
        } catch (Throwable $error) {
            $this->finish($id, null, $error);

            return;
        }
        $this->finish($id, $connection, null);
    }

    /** @return resource */
    private function open(DialEndpoint $endpoint): mixed
    {
        $errorCode = 0;
        $error = '';
        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);

        try {
            $stream = stream_socket_client(
                $endpoint->uri,
                $errorCode,
                $error,
                0.0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                // Never inherit process-global TLS options from another request,
                // test, or connection (including a stale client certificate).
                stream_context_create(),
            );
        } finally {
            restore_error_handler();
        }
        if (!is_resource($stream)) {
            throw new RuntimeException(sprintf('Could not create outgoing socket: %s (%d).', $error, $errorCode));
        }
        if (!stream_set_blocking($stream, false)) {
            fclose($stream);

            throw new RuntimeException('Cannot make outgoing socket nonblocking.');
        }

        return $stream;
    }

    private function ready(int $id): void
    {
        $job = $this->pending[$id] ?? null;
        if ($job === null) {
            return;
        }

        try {
            $this->checkConnected($job['stream']);
            if ($job['options']->addressPolicy !== null) {
                $peer = stream_socket_get_name($job['stream'], true);
                if (!is_string($peer)) {
                    throw new RuntimeException('Cannot verify connected peer for outbound address policy.');
                }
                $job['options']->addressPolicy->assertConnected($job['candidateIp'] ?? '', $peer);
            }
            if ($job['options']->tls !== null) {
                if ($job['watcher'] !== null) {
                    $this->loop->cancel($job['watcher']);
                }
                $job['state']->transition(DialAttemptPhase::HANDSHAKING);
                $tls = ClientTlsHandshake::start(
                    $this->loop,
                    $job['stream'],
                    $job['options']->tls,
                    function (?string $protocol) use ($id): void {
                        $this->handoff($id, $protocol);
                    },
                    function (Throwable $error) use ($id): void {
                        $this->finish($id, null, $error);
                    },
                );
                $this->pending[$id] = [...$job, 'watcher' => null, 'tls' => $tls];

                return;
            }

            $this->handoff($id, null);
        } catch (Throwable $error) {
            $this->finish($id, null, $error);

            return;
        }
    }

    private function release(int $id, bool $handedOff = false, bool $cancelled = false): void
    {
        $job = $this->pending[$id] ?? null;
        if ($job === null) {
            return;
        }
        unset($this->pending[$id]);
        foreach ([$job['watcher'], $job['timer']] as $handle) {
            if ($handle !== null) {
                $this->loop->cancel($handle);
            }
        }
        $job['subscription']?->unsubscribe();
        $job['tls']?->cancel();
        if (!$handedOff) {
            if (in_array($job['state']->phase(), [
                DialAttemptPhase::CONNECTING,
                DialAttemptPhase::HANDSHAKING,
                DialAttemptPhase::READY,
            ], true)) {
                $job['state']->transition($cancelled ? DialAttemptPhase::CANCELLED : DialAttemptPhase::FAILED);
            }
            if (is_resource($job['stream'])) {
                fclose($job['stream']);
            }
        }
    }
}
