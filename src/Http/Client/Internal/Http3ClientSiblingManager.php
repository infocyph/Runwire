<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Closure;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Qpack\ReadyFieldSection;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\QuicClientSession;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** @internal Bounded sibling exchanges over one verified, already-owned QUIC session. */
final class Http3ClientSiblingManager
{
    private readonly Closure $fatal;

    private readonly Closure $feedback;

    private readonly Closure $onEmpty;

    private bool $closed = false;

    /** @var array<int, Http3ClientSiblingExchange> */
    private array $exchanges = [];

    private int $nextId = 1;

    private bool $ready = false;

    private bool $settings = false;

    /** @var array<int, int> */
    private array $streams = [];

    /**
     * @param callable(Throwable):void $fatal
     * @param callable():void $feedback
     * @param callable():void $onEmpty
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly QuicClientSession $session,
        private readonly Http3ClientWireCodec $codec,
        callable $fatal,
        callable $feedback,
        callable $onEmpty,
    ) {
        $this->fatal = Closure::fromCallable($fatal);
        $this->feedback = Closure::fromCallable($feedback);
        $this->onEmpty = Closure::fromCallable($onEmpty);
    }

    /** Whether any sibling still owns pending admission or a live response. */
    public function active(): bool
    {
        return $this->exchanges !== [];
    }

    /** Cancel just one sibling and reset its native stream when present. */
    public function cancel(int $id, Throwable $error): bool
    {
        $state = $this->exchanges[$id] ?? null;
        if ($state === null) {
            return false;
        }
        if ($state->stream > 0) {
            $this->codec->cancelResponseStream($state->wireStreamId);
            ($this->feedback)();
        }
        if ($state->stream > 0 && !$state->peerEof && $this->session->active()) {
            $this->command('reset', $state->stream, '', false, static function (): void {});
        }
        $this->complete($id, $error);

        return true;
    }

    /** Finish all pending siblings when their owning connection closes. */
    public function close(Throwable $error): void
    {
        $this->closed = true;
        foreach (array_keys($this->exchanges) as $id) {
            $this->complete($id, $error);
        }
    }

    /**
     * Register an independently cancellable request without allocating a second QUIC session.
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
        if ($this->closed || $this->nextId === PHP_INT_MAX) {
            throw new RuntimeException('HTTP/3 sibling session is closed or exhausted.');
        }
        $limit = min(
            10.0,
            $request->deadline?->remainingSeconds() ?? INF,
            $request->cancellation?->deadline()->remainingSeconds() ?? INF,
        );
        if ($limit <= 0) {
            throw new InvalidArgumentException('HTTP/3 sibling deadline already expired.');
        }
        $id = $this->nextId++;
        $state = new Http3ClientSiblingExchange($request, $onHeaders, $onBody, $completion, $onTrailers);
        $this->exchanges[$id] = $state;
        $state->timeout = $this->loop->delay($limit, function () use ($id): void {
            $this->cancel($id, new RuntimeException('HTTP/3 sibling exchange deadline expired.'));
        });
        $token = $request->cancellation;
        if ($token?->isCancelled()) {
            $this->cancel($id, new CancelledException($token->reason() ?? CancellationReason::HOST_CANCELLED));

            return $id;
        }
        $state->cancellation = $token?->onCancel(function () use ($id, $token): void {
            $this->cancel($id, new CancelledException($token->reason() ?? CancellationReason::HOST_CANCELLED));
        });
        if ($this->ready && array_key_exists($id, $this->exchanges)) {
            $this->open($id);
        }

        return $id;
    }

    /** Permit request streams only once control and local QPACK critical streams exist. */
    public function start(): void
    {
        if ($this->ready || $this->closed) {
            return;
        }
        $this->ready = true;
        foreach (array_keys($this->exchanges) as $id) {
            $this->open($id);
        }
    }

    /** Release a QPACK-blocked sibling after matching peer encoder instructions. */
    public function unblock(ReadyFieldSection $ready): bool
    {
        $id = $this->streams[$ready->streamId] ?? null;
        if ($id === null) {
            return false;
        }
        $state = $this->exchanges[$id] ?? null;
        if ($state === null || $state->response === null) {
            throw new Http3Exception(ErrorCode::QPACK_DECOMPRESSION_FAILED, 'Unknown live sibling QPACK section.');
        }
        $state->response->resumeQpack($ready);
        ($this->feedback)();
        if ($state->response->isFinished()) {
            $this->complete($id, null);
        }

        return true;
    }

    /** Allow independently read-ready sibling responses after verified peer SETTINGS. */
    public function verifiedSettings(): void
    {
        if ($this->settings) {
            return;
        }
        $this->settings = true;
        foreach (array_keys($this->exchanges) as $id) {
            $this->read($id);
        }
    }

    /**
     * @param callable(array<string,mixed>):void $next
     */

    /** @param array<string,mixed> $result */
    private function acceptRead(int $id, array $result): void
    {
        $state = $this->exchanges[$id] ?? null;
        if ($state === null || $state->response === null) {
            return;
        }
        $state->readActive = false;
        $wire = $result['data'] ?? null;
        $decoded = is_string($wire) ? base64_decode($wire, true) : false;
        if (!is_string($decoded)) {
            throw new RuntimeException('Invalid multiplexed HTTP/3 response bytes.');
        }
        $state->response->push($decoded);
        ($this->feedback)();
        if (($result['eof'] ?? null) === true) {
            $state->peerEof = true;
            $state->response->finish();
            if ($state->response->isFinished()) {
                $this->complete($id, null);
            }

            return;
        }
        $this->poll(fn() => $this->read($id));
    }

    /**
     * @param array<string,mixed> $result
     * @param callable():void $next
     */
    private function acceptWrite(
        int $id,
        array $result,
        string $bytes,
        bool $fin,
        int $offset,
        callable $next,
        int $length,
        bool $last,
    ): void {
        if (!array_key_exists($id, $this->exchanges)) {
            return;
        }
        $written = $result['written'] ?? null;
        if (!is_int($written) || $written < 0 || $written > $length || ($last && $written !== $length)) {
            throw new RuntimeException('Invalid partial multiplexed QUIC write completion.');
        }
        if ($written === 0) {
            $this->poll(fn() => $this->write($id, $bytes, $fin, $next, $offset));

            return;
        }
        $this->write($id, $bytes, $fin, $next, $offset + $written);
    }

    private function command(string $op, int $stream, string $data, bool $fin, callable $next): void
    {
        if ($this->closed) {
            return;
        }
        try {
            $this->session->command(
                $op,
                $stream,
                $data,
                $fin,
                function (mixed $result, ?Throwable $error) use ($next): void {
                    if ($this->closed) {
                        return;
                    }
                    if ($error !== null || !is_array($result)) {
                        ($this->fatal)($error ?? new RuntimeException('Invalid multiplexed QUIC worker result.'));

                        return;
                    }
                    try {
                        $next($result);
                    } catch (Throwable $failure) {
                        ($this->fatal)($failure);
                    }
                },
            );
        } catch (Throwable $error) {
            ($this->fatal)($error);
        }
    }

    private function complete(int $id, ?Throwable $error): void
    {
        $state = $this->exchanges[$id] ?? null;
        if ($state === null) {
            return;
        }
        unset($this->exchanges[$id]);
        if ($state->stream > 0) {
            unset($this->streams[$state->wireStreamId]);
        }
        if ($state->timeout !== null) {
            $this->loop->cancel($state->timeout);
        }
        $state->cancellation?->unsubscribe();
        ($state->completion)($error);
        if (!$this->closed && $this->exchanges === []) {
            ($this->onEmpty)();
        }
    }

    private function open(int $id): void
    {
        $this->command('open_bidi', 0, '', false, function (array $opened) use ($id): void {
            $state = $this->exchanges[$id] ?? null;
            $stream = $opened['stream'] ?? null;
            if ($state === null) {
                return;
            }
            $wireId = $opened['wire_stream_id'] ?? null;
            if (!is_int($stream) || $stream < 1 || !is_int($wireId) || $wireId < 0) {
                throw new RuntimeException('Multiplexed QUIC admission has invalid worker or wire stream ID.');
            }
            $state->stream = $stream;
            $state->wireStreamId = $wireId;
            $this->streams[$wireId] = $id;
            $state->response = new Http3ClientResponseStream(
                $wireId,
                $state->onHeaders,
                $state->onBody,
                onTrailers: $state->onTrailers,
                codec: $this->codec,
            );
            $wire = $this->codec->encodeRequestHeaders($state->request, $wireId);
            $this->write($id, $wire, $state->request->body === null, function () use ($id): void {
                $this->upload($id);
            });
        });
    }

    private function poll(callable $again): void
    {
        $this->loop->delay(0.01, function () use ($again): void {
            if (!$this->closed) {
                $again();
            }
        });
    }

    private function read(int $id): void
    {
        $state = $this->exchanges[$id] ?? null;
        if (!$this->settings || $state === null || !$state->sent || $state->readActive || $state->peerEof) {
            return;
        }
        $state->readActive = true;
        $this->command('read', $state->stream, '', false, function (array $result) use ($id): void {
            /** @var array<string,mixed> $result */
            $this->acceptRead($id, $result);
        });
    }

    private function sent(int $id): void
    {
        $state = $this->exchanges[$id] ?? null;
        if ($state === null) {
            return;
        }
        $state->sent = true;
        $this->read($id);
    }

    private function upload(int $id): void
    {
        $state = $this->exchanges[$id] ?? null;
        if ($state === null) {
            return;
        }
        $body = $state->request->body;
        if ($body === null) {
            $this->sent($id);

            return;
        }
        if ($body->eof()) {
            $trailers = $body->trailers();
            $last = $trailers === null ? '' : $this->codec->encodeRequestTrailers($trailers, $state->wireStreamId);
            $this->write($id, $last, true, function () use ($id): void {
                $this->sent($id);
            });

            return;
        }
        $part = $body->read(4_096);
        if ($part === '') {
            $this->poll(fn() => $this->upload($id));

            return;
        }
        $size = strlen($part);
        if ($size > 4_096 || $size > 16_777_216 - $state->uploadedBytes) {
            throw new RuntimeException('Multiplexed HTTP/3 upload byte budget exceeded.');
        }
        $state->uploadedBytes += $size;
        $wire = new Frame(FrameType::DATA->value, $part)->encode();
        $this->write($id, $wire, false, function () use ($id): void {
            $this->upload($id);
        });
    }

    /** @param callable():void $next */
    private function write(int $id, string $bytes, bool $fin, callable $next, int $offset = 0): void
    {
        $state = $this->exchanges[$id] ?? null;
        if ($state === null) {
            return;
        }
        if ($bytes === '' && $fin) {
            $bytes = new Frame(FrameType::DATA->value)->encode();
        }
        $remaining = strlen($bytes) - $offset;
        if ($remaining === 0) {
            $next();

            return;
        }
        $length = min(4_096, $fin && $remaining > 1 ? $remaining - 1 : $remaining);
        $chunk = substr($bytes, $offset, $length);
        $last = $fin && $remaining === 1;
        $this->command('write', $state->stream, $chunk, $last, function (array $result) use (
            $id,
            $bytes,
            $fin,
            $offset,
            $next,
            $length,
            $last,
        ): void {
            /** @var array<string,mixed> $result */
            $this->acceptWrite($id, $result, $bytes, $fin, $offset, $next, $length, $last);
        });
    }
}
