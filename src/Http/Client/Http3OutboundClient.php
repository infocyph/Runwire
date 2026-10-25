<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Client\Internal\Http3ClientControl;
use Infocyph\Runwire\Http\Client\Internal\Http3ClientResponseStream;
use Infocyph\Runwire\Http\Client\Internal\Http3ClientWireCodec;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\QuicClientOptions;
use Infocyph\Runwire\Network\QuicClientSession;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Experimental one-shot HTTPS/h3 client with off-event-loop native QUIC I/O.
 *
 * Supports one bodyless request with bounded static-QPACK response streaming.
 * No implicit H1/H2 fallback, connection reuse, dynamic QPACK or trailers.
 */
final class Http3OutboundClient
{
    private readonly Closure $completion;

    private readonly Http3ClientControl $control;

    private readonly Closure $onBody;

    private readonly Closure $onHeaders;

    private readonly QuicClientSession $session;

    private ?CancellationSubscription $cancellation = null;

    private bool $finished = false;

    private bool $peerControlFound = false;

    private ?Http3ClientResponseStream $response = null;

    private int $responseStream = 0;

    private ?int $timeout = null;

    private int $unrelatedStreams = 0;

    /**
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Throwable):void $completion
     */
    private function __construct(
        private readonly LoopInterface $loop,
        private readonly ClientRequest $request,
        private readonly QuicClientOptions $options,
        callable $onHeaders,
        callable $onBody,
        callable $completion,
    ) {
        $this->onHeaders = Closure::fromCallable($onHeaders);
        $this->onBody = Closure::fromCallable($onBody);
        $this->completion = Closure::fromCallable($completion);
        $this->session = new QuicClientSession($loop);
        $this->control = new Http3ClientControl();
    }

    /**
     * Enqueue one verified QUIC+h3 exchange without blocking the owner reactor.
     *
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Throwable):void $completion
     */
    public static function connect(
        LoopInterface $loop,
        ClientRequest $request,
        QuicClientOptions $options,
        callable $onHeaders,
        callable $onBody,
        callable $completion,
    ): self {
        $parts = self::validatedOrigin($request, $options);
        // Fail unsafe framing before opening any native session or DNS path.
        new Http3ClientWireCodec()->encodeRequestHeaders($request, 1);
        $client = new self($loop, $request, $options, $onHeaders, $onBody, $completion);
        $client->start($parts['host'], $parts['port']);

        return $client;
    }

    /** Abort exactly once and retire every native worker resource. */
    public function close(): void
    {
        $this->finish(new RuntimeException('HTTP/3 exchange was closed before completion.'));
    }

    /** @param array<string,mixed> $result */
    private static function decodeBytes(array $result): string
    {
        $data = $result['data'] ?? null;
        if (!is_string($data) || ($bytes = base64_decode($data, true)) === false) {
            throw new RuntimeException('Invalid HTTP/3 worker binary result.');
        }

        return $bytes;
    }

    /** @param array<string,mixed> $result */
    private static function streamId(array $result): int
    {
        $stream = $result['stream'] ?? null;
        if (!is_int($stream) || $stream < 1) {
            throw new RuntimeException('Invalid HTTP/3 worker stream handle.');
        }

        return $stream;
    }

    /** @return array{host:string,port:int} */
    private static function validatedOrigin(ClientRequest $request, QuicClientOptions $options): array
    {
        $parts = parse_url($request->uri);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null)) {
            throw new InvalidArgumentException('HTTP/3 requires a secure absolute HTTPS origin.');
        }
        $host = trim($parts['host'], '[]');
        if (strcasecmp($options->peerName, $host) !== 0 || $options->alpnProtocols !== ['h3']) {
            throw new InvalidArgumentException('HTTP/3 QUIC must verify the original origin with h3-only ALPN.');
        }
        if ($request->protocol->negotiate('h3', true, true) !== ProtocolVersion::HTTP_3) {
            throw new InvalidArgumentException('HTTP/3 client protocol policy rejected h3.');
        }
        if ($request->body !== null) {
            throw new InvalidArgumentException('HTTP/3 streaming request uploads are not yet supported.');
        }

        return ['host' => $host, 'port' => $parts['port'] ?? 443];
    }

    private function acceptPeerControl(string $bytes, int $stream, bool $eof): void
    {
        if (!$this->peerControlFound && $bytes !== '' && $bytes[0] !== "\x00") {
            if (++$this->unrelatedStreams > 8) {
                throw new RuntimeException('Excessive non-control HTTP/3 peer streams.');
            }
            $this->receiveControl();

            return;
        }
        if ($bytes !== '') {
            $this->peerControlFound = true;
        }
        $this->control->receivePeerControl($bytes);
        if ($eof) {
            $this->control->closePeerControl();
        }
        if ($this->control->peerSettings() !== null) {
            $this->readResponse();
        } else {
            $this->poll(fn() => $this->readControl($stream));
        }
    }

    /** @param array<string,mixed> $result */
    private function assertWritten(array $result, int $length): void
    {
        if (($result['written'] ?? null) !== $length) {
            throw new RuntimeException('HTTP/3 QUIC worker could not atomically send the bounded frame.');
        }
    }

    /** @param callable(array<string,mixed>):void $next */
    private function command(string $op, int $stream, string $data, bool $fin, callable $next): void
    {
        if ($this->finished) {
            return;
        }
        try {
            $this->session->command(
                $op,
                $stream,
                $data,
                $fin,
                function (mixed $result, ?Throwable $error) use ($next): void {
                    if ($this->finished) {
                        return;
                    }
                    if ($error !== null || !is_array($result)) {
                        $this->finish($error ?? new RuntimeException('Invalid HTTP/3 worker response.'));

                        return;
                    }

                    try {
                        /** @var array<string,mixed> $validated */
                        $validated = $result;
                        $next($validated);
                    } catch (Throwable $failure) {
                        $this->finish($failure);
                    }
                },
                $this->request->cancellation,
                $this->request->deadline,
            );
        } catch (Throwable $error) {
            $this->finish($error);
        }
    }

    private function finish(?Throwable $error): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        if ($this->timeout !== null) {
            $this->loop->cancel($this->timeout);
            $this->timeout = null;
        }
        $this->cancellation?->unsubscribe();
        $this->cancellation = null;
        $this->session->dispose();
        ($this->completion)($error);
    }

    private function poll(callable $again): void
    {
        $this->loop->delay(0.01, function () use ($again): void {
            if (!$this->finished) {
                $again();
            }
        });
    }

    private function readControl(int $stream): void
    {
        $this->command(
            'read',
            $stream,
            '',
            false,
            function (array $result) use ($stream): void {
                $this->acceptPeerControl(
                    self::decodeBytes($result),
                    $stream,
                    ($result['eof'] ?? null) === true,
                );
            },
        );
    }

    private function readResponse(): void
    {
        $response = $this->response;
        if ($response === null) {
            throw new RuntimeException('HTTP/3 request stream was not started.');
        }
        $stream = $this->responseStream;
        $this->command(
            'read',
            $stream,
            '',
            false,
            function (array $result) use ($response): void {
                $response->push(self::decodeBytes($result));
                if (($result['eof'] ?? null) === true) {
                    $response->finish();
                    $this->finish(null);
    
                    return;
                }
                $this->poll(fn() => $this->readResponse());
            },
        );
    }

    /** Locate the mandatory peer control stream before processing the response. */
    private function receiveControl(): void
    {
        $this->command(
            'accept',
            0,
            '',
            false,
            function (array $accepted): void {
                if (($accepted['pending'] ?? null) === true) {
                    $this->poll(fn() => $this->receiveControl());
    
                    return;
                }
                if (($accepted['incoming'] ?? null) !== true || ($accepted['bidirectional'] ?? null) !== false
                    || !is_int($accepted['stream'] ?? null)) {
                    throw new RuntimeException('Invalid peer HTTP/3 control stream admission.');
                }
                $this->readControl($accepted['stream']);
            },
        );
    }

    private function sendControl(int $stream): void
    {
        if ($stream < 1) {
            $this->finish(new RuntimeException('HTTP/3 QUIC cannot open its control stream.'));

            return;
        }
        $data = $this->control->start();
        $this->command(
            'write',
            $stream,
            $data,
            false,
            function (array $result) use ($data): void {
                $this->assertWritten($result, strlen($data));
                $this->command(
                    'open_bidi',
                    0,
                    '',
                    false,
                    function (array $opened): void {
                        $this->sendRequest(self::streamId($opened));
                    },
                );
            },
        );
    }

    private function sendRequest(int $stream): void
    {
        if ($stream < 1) {
            $this->finish(new RuntimeException('HTTP/3 QUIC cannot open a bidirectional request stream.'));

            return;
        }
        $bytes = new Http3ClientWireCodec()->encodeRequestHeaders($this->request, $stream);
        $this->responseStream = $stream;
        $this->response = new Http3ClientResponseStream($stream, $this->onHeaders, $this->onBody);
        $this->command(
            'write',
            $stream,
            $bytes,
            true,
            function (array $result) use ($bytes): void {
                $this->assertWritten($result, strlen($bytes));
                $this->receiveControl();
            },
        );
    }

    private function start(string $host, int $port): void
    {
        $token = $this->request->cancellation;
        if ($token?->isCancelled()) {
            $this->finish(new CancelledException($token->reason() ?? CancellationReason::HOST_CANCELLED));

            return;
        }
        $seconds = min(
            10.0,
            $this->request->deadline?->remainingSeconds() ?? INF,
            $token?->deadline()->remainingSeconds() ?? INF,
        );
        if ($seconds <= 0) {
            $this->finish(new RuntimeException('HTTP/3 exchange deadline already expired.'));

            return;
        }
        $this->timeout = $this->loop->delay($seconds, function (): void {
            $this->finish(new RuntimeException('HTTP/3 exchange deadline expired.'));
        });
        $this->cancellation = $token?->onCancel(function () use ($token): void {
            $this->finish(new CancelledException($token->reason() ?? CancellationReason::HOST_CANCELLED));
        });
        if ($this->finished) {
            return;
        }
        try {
            $this->session->connect(
                $host,
                $port,
                $this->options,
                function (mixed $result, ?Throwable $error): void {
                    if ($error !== null || !is_array($result) || ($result['alpn'] ?? null) !== 'h3'
                        || ($result['verified'] ?? null) !== true) {
                        $this->finish($error ?? new RuntimeException('HTTP/3 QUIC h3 TLS/ALPN verification failed.'));

                        return;
                    }
                    $this->command(
                        'open_uni',
                        0,
                        '',
                        false,
                        function (array $opened): void {
                            $this->sendControl(self::streamId($opened));
                        },
                    );
                },
                $this->request->cancellation,
                $this->request->deadline,
            );
        } catch (Throwable $error) {
            $this->finish($error);
        }
    }
}
