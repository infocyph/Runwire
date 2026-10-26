<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Client\Internal\Http3ClientControl;
use Infocyph\Runwire\Http\Client\Internal\Http3ClientOrigin;
use Infocyph\Runwire\Http\Client\Internal\Http3ClientPeerAuxiliary;
use Infocyph\Runwire\Http\Client\Internal\Http3ClientPeerStreamRegistry;
use Infocyph\Runwire\Http\Client\Internal\Http3ClientResponseStream;
use Infocyph\Runwire\Http\Client\Internal\Http3ClientSiblingManager;
use Infocyph\Runwire\Http\Client\Internal\Http3ClientWireCodec;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Enum\StreamType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\Qpack\ReadyFieldSection;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\QuicClientOptions;
use Infocyph\Runwire\Network\QuicClientSession;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Experimental HTTPS/h3 client with shared-session siblings and off-loop QUIC I/O.
 *
 * Supports bounded incremental DATA uploads and static-QPACK response streaming.
 * No implicit H1/H2 fallback; sibling multiplexing and bounded dynamic QPACK remain experimental.
 */
final class Http3OutboundClient
{
    private const int MAX_UPLOAD_BYTES = 16_777_216;

    private const int UPLOAD_CHUNK_BYTES = 4_096;

    private readonly Http3ClientPeerAuxiliary $auxiliary;

    private readonly Http3ClientWireCodec $codec;

    private readonly Closure $completion;

    private readonly Http3ClientControl $control;

    private readonly Closure $onBody;

    private readonly Closure $onHeaders;

    private readonly ?Closure $onTrailers;

    private readonly Http3ClientPeerStreamRegistry $peerStreams;

    private readonly QuicClientSession $session;

    private ?CancellationSubscription $cancellation = null;

    private bool $finished = false;

    private bool $localStreamsReady = false;

    private bool $peerSettingsSeen = false;

    private int $qpackDecoderStream = 0;

    private bool $qpackFeedbackBusy = false;

    private string $qpackFeedbackQueue = '';

    private ?Http3ClientResponseStream $response = null;

    private int $responseStream = 0;

    private int $responseWireStreamId = 0;

    private bool $rootCompleted = false;

    private ?Http3ClientSiblingManager $siblings = null;

    private ?int $timeout = null;

    private int $uploadedBytes = 0;

    /**
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Throwable):void $completion
     * @param null|callable(\Infocyph\Runwire\Http\Headers):void $onTrailers
     */
    private function __construct(
        private readonly LoopInterface $loop,
        private readonly ClientRequest $request,
        private readonly QuicClientOptions $options,
        callable $onHeaders,
        callable $onBody,
        callable $completion,
        ?callable $onTrailers,
    ) {
        $this->onHeaders = Closure::fromCallable($onHeaders);
        $this->onBody = Closure::fromCallable($onBody);
        $this->onTrailers = $onTrailers === null ? null : Closure::fromCallable($onTrailers);
        $this->completion = Closure::fromCallable($completion);
        $this->session = new QuicClientSession($loop);
        $this->control = new Http3ClientControl(decoderCapacity: 4_096, maxBlocked: 8);
        $this->codec = new Http3ClientWireCodec(decoderCapacity: 4_096, maxBlocked: 8);
        $this->peerStreams = new Http3ClientPeerStreamRegistry();
        $this->auxiliary = new Http3ClientPeerAuxiliary($this->codec);
    }

    /**
     * Enqueue one verified QUIC+h3 exchange without blocking the owner reactor.
     *
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Throwable):void $completion
     * @param null|callable(\Infocyph\Runwire\Http\Headers):void $onTrailers
     */
    public static function connect(
        LoopInterface $loop,
        ClientRequest $request,
        QuicClientOptions $options,
        callable $onHeaders,
        callable $onBody,
        callable $completion,
        ?callable $onTrailers = null,
    ): self {
        $parts = Http3ClientOrigin::validate($request, $options);
        // Fail unsafe framing before opening any native session or DNS path.
        new Http3ClientWireCodec()->encodeRequestHeaders($request, 1);
        $client = new self($loop, $request, $options, $onHeaders, $onBody, $completion, $onTrailers);
        $client->start($parts['host'], $parts['port']);

        return $client;
    }

    /** Abort exactly once and retire every native worker resource. */
    public function close(): void
    {
        $this->finish(new RuntimeException('HTTP/3 exchange was closed before completion.'));
    }

    /**
     * Enqueue an independently scoped sibling request on this same verified QUIC connection.
     * All siblings must use precisely the original HTTPS origin and h3-only ALPN.
     *
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Throwable):void $completion
     * @param null|callable(\Infocyph\Runwire\Http\Headers):void $onTrailers
     */
    public function enqueue(
        ClientRequest $request,
        callable $onHeaders,
        callable $onBody,
        callable $completion,
        ?callable $onTrailers = null,
    ): int {
        if ($this->finished || $this->control->peerGoaway() !== null) {
            throw new RuntimeException('HTTP/3 session is closed or draining after GOAWAY.');
        }
        if (Http3ClientOrigin::validate($request, $this->options) !== Http3ClientOrigin::validate($this->request, $this->options)) {
            throw new InvalidArgumentException('HTTP/3 sibling must use the exact verified original HTTPS origin.');
        }
        $this->codec->encodeRequestHeaders($request, 1);
        $this->siblings ??= new Http3ClientSiblingManager(
            $this->loop,
            $this->session,
            $this->codec,
            fn(Throwable $error) => $this->finish($error),
            fn() => $this->flushQpackFeedback(),
            fn() => $this->finishIfEmpty(),
            maxActiveStreams: max(1, $this->options->maxOpenStreams - 7),
        );
        if ($this->localStreamsReady) {
            $this->siblings->start();
        }
        if ($this->peerSettingsSeen) {
            $this->siblings->verifiedSettings();
        }

        return $this->siblings->enqueue($request, $onHeaders, $onBody, $completion, $onTrailers);
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

    private function acceptPeerAuxiliary(int $type, string $bytes, int $stream, bool $eof): void
    {
        $outcome = $this->auxiliary->accept(
            $type,
            $bytes,
            $eof,
            $this->response,
            $this->responseWireStreamId,
            fn(ReadyFieldSection $ready): bool => $this->siblings?->unblock($ready) ?? false,
        );
        if ($this->finished) {
            return;
        }
        $this->flushQpackFeedback();
        if ($outcome['completed']) {
            $this->finish(null);

            return;
        }
        if ($outcome['watch']) {
            $this->poll(fn() => $this->readPeerAuxiliary($stream, $type));
        }
    }

    private function acceptPeerControl(string $bytes, int $stream, bool $eof): void
    {
        $probe = $this->peerStreams->push($stream, $bytes, $eof);
        if ($probe === null) {
            $this->poll(fn() => $this->readControl($stream));

            return;
        }
        if ($probe['initial'] && $probe['type'] !== StreamType::CONTROL->value) {
            $this->acceptPeerAuxiliary($probe['type'], $probe['payload'], $stream, $eof);
            // Control SETTINGS can arrive before independently owned QPACK streams.
            // Continue accepting critical peer streams for the whole connection.
            $this->receiveControl();

            return;
        }
        if ($probe['initial']) {
            $this->receiveControl();
        }
        $bytes = $probe['wire'];

        $this->control->receivePeerControl($bytes);
        $this->siblings?->drain($this->control->peerGoaway());
        if ($eof) {
            $this->control->closePeerControl();
        }
        if ($this->control->peerSettings() !== null && !$this->peerSettingsSeen) {
            $this->peerSettingsSeen = true;
            $this->siblings?->verifiedSettings();
            $this->readResponse();
        }
        // The peer's critical control stream stays live for the entire session.
        // Re-read after SETTINGS to detect premature FIN and later control frames.
        $this->poll(fn() => $this->readControl($stream));
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
        if ($error === null && !$this->rootCompleted && $this->siblings?->active()) {
            $this->rootCompleted = true;
            $this->retireRootDeadline();
            ($this->completion)(null);

            return;
        }
        $this->finished = true;
        $this->retireRootDeadline();
        $this->siblings?->close($error ?? new RuntimeException('HTTP/3 connection closed.'));
        $this->session->dispose();
        if (!$this->rootCompleted) {
            ($this->completion)($error);
        }
    }

    private function finishIfEmpty(): void
    {
        if ($this->rootCompleted) {
            $this->finish(null);
        }
    }

    /** Serialize decoder feedback on the one locally owned critical stream. */
    private function flushQpackFeedback(): void
    {
        $this->qpackFeedbackQueue .= $this->codec->takeDecoderInstructions();
        if ($this->qpackFeedbackBusy || $this->qpackFeedbackQueue === '' || $this->qpackDecoderStream < 1) {
            return;
        }
        $this->qpackFeedbackBusy = true;
        $bytes = $this->qpackFeedbackQueue;
        $this->qpackFeedbackQueue = '';
        $this->writeAll($this->qpackDecoderStream, $bytes, false, function (): void {
            $this->qpackFeedbackBusy = false;
            $this->flushQpackFeedback();
        });
    }

    /** Establish one persistent local QPACK instruction stream before requests. */
    private function openQpackStream(StreamType $type, callable $completed): void
    {
        $this->command('open_uni', 0, '', false, function (array $opened) use ($type, $completed): void {
            $stream = self::streamId($opened);
            if ($type === StreamType::QPACK_DECODER) {
                $this->qpackDecoderStream = $stream;
            }
            $this->writeAll($stream, $this->control->localQpackPreamble($type), false, function () use ($completed): void {
                $this->flushQpackFeedback();
                $completed();
            });
        });
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

    private function readPeerAuxiliary(int $stream, int $type): void
    {
        $this->command('read', $stream, '', false, function (array $result) use ($stream, $type): void {
            $this->acceptPeerAuxiliary($type, self::decodeBytes($result), $stream, ($result['eof'] ?? null) === true);
        });
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
                $this->flushQpackFeedback();
                if (($result['eof'] ?? null) === true) {
                    $response->finish();
                    if ($response->isFinished()) {
                        $this->finish(null);
                    }

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

    private function retireRootDeadline(): void
    {
        if ($this->timeout !== null) {
            $this->loop->cancel($this->timeout);
            $this->timeout = null;
        }
        $this->cancellation?->unsubscribe();
        $this->cancellation = null;
    }

    private function sendControl(int $stream): void
    {
        if ($stream < 1) {
            $this->finish(new RuntimeException('HTTP/3 QUIC cannot open its control stream.'));

            return;
        }
        $data = $this->control->start();
        $this->writeAll(
            $stream,
            $data,
            false,
            function (): void {
                $this->openQpackStream(StreamType::QPACK_ENCODER, function (): void {
                    $this->openQpackStream(StreamType::QPACK_DECODER, function (): void {
                        $this->localStreamsReady = true;
                        $this->command('open_bidi', 0, '', false, function (array $opened): void {
                            $this->sendRequest(self::streamId($opened), Http3ClientOrigin::wireId($opened));
                            $this->siblings?->start();
                        });
                    });
                });
            },
        );
    }

    private function sendRequest(int $stream, int $wireStreamId): void
    {
        if ($stream < 1) {
            $this->finish(new RuntimeException('HTTP/3 QUIC cannot open a bidirectional request stream.'));

            return;
        }
        $bytes = $this->codec->encodeRequestHeaders($this->request, $wireStreamId);
        $this->responseStream = $stream;
        $this->responseWireStreamId = $wireStreamId;
        $this->response = new Http3ClientResponseStream(
            $wireStreamId,
            $this->onHeaders,
            $this->onBody,
            onTrailers: $this->onTrailers,
            codec: $this->codec,
        );
        $this->writeAll(
            $stream,
            $bytes,
            $this->request->body === null,
            function (): void {
                $this->sendUpload();
            },
        );
    }

    /** Pull one bounded producer chunk only after the previous QUIC write completes. */
    private function sendUpload(): void
    {
        $body = $this->request->body;
        if ($body === null) {
            $this->receiveControl();

            return;
        }
        if ($body->eof()) {
            $trailers = $body->trailers();
            $last = $trailers === null ? '' : $this->codec->encodeRequestTrailers($trailers, $this->responseWireStreamId);
            $this->writeAll($this->responseStream, $last, true, function (): void {
                $this->receiveControl();
            });

            return;
        }

        $chunk = $body->read(self::UPLOAD_CHUNK_BYTES);
        if ($chunk === '') {
            $this->poll(fn() => $this->sendUpload());

            return;
        }
        $length = strlen($chunk);
        if ($length > self::UPLOAD_CHUNK_BYTES || $length > self::MAX_UPLOAD_BYTES - $this->uploadedBytes) {
            throw new RuntimeException('HTTP/3 streaming upload exceeded its bounded byte budget.');
        }
        $this->uploadedBytes += $length;
        $frame = new Frame(FrameType::DATA->value, $chunk)->encode();
        $this->writeAll($this->responseStream, $frame, false, function (): void {
            $this->sendUpload();
        });
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

    /**
     * Write a complete frame through partial, bounded native QUIC writes.
     *
     * @param callable():void $completed
     */
    private function writeAll(int $stream, string $bytes, bool $fin, callable $completed): void
    {
        if ($bytes === '' && $fin) {
            // Current native php-quic only delivers FIN reliably with bytes.
            // An empty HTTP/3 DATA frame is a valid, bounded end marker.
            $bytes = new Frame(FrameType::DATA->value)->encode();
        }
        $this->writeChunk($stream, $bytes, 0, $fin, $completed);
    }

    /**
     * Retain the trailing byte for the native FIN write; retry partial non-FIN
     * writes without dropping framing bytes or advancing the response early.
     *
     * @param callable():void $completed
     */
    private function writeChunk(int $stream, string $bytes, int $offset, bool $fin, callable $completed): void
    {
        $remaining = strlen($bytes) - $offset;
        if ($remaining === 0) {
            $completed();

            return;
        }

        if ($fin && $remaining === 1) {
            $this->command('write', $stream, substr($bytes, $offset, 1), true, function (array $result) use ($completed): void {
                $this->assertWritten($result, 1);
                $completed();
            });

            return;
        }

        $length = min(4_096, $fin ? $remaining - 1 : $remaining);
        $part = substr($bytes, $offset, $length);
        $this->command('write', $stream, $part, false, function (array $result) use (
            $stream,
            $bytes,
            $offset,
            $fin,
            $completed,
            $part,
        ): void {
            $written = $result['written'] ?? null;
            if (!is_int($written) || $written < 0 || $written > strlen($part)) {
                throw new RuntimeException('Invalid partial HTTP/3 native QUIC write result.');
            }
            if ($written === 0) {
                $this->poll(fn() => $this->writeChunk($stream, $bytes, $offset, $fin, $completed));

                return;
            }
            $this->writeChunk($stream, $bytes, $offset + $written, $fin, $completed);
        });
    }
}
