<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Client\Internal\Http2ClientSession;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Http2\FrameParser;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Experimental single-request HTTP/2 exchange on verified TLS ALPN h2.
 * Unpooled and one-shot: no stream multiplexing, trailers or server push.
 */
final class Http2ConnectedExchange
{
    private readonly Closure $completion;

    private readonly FrameParser $frames;

    private readonly Http2ClientSession $session;

    private ?int $closeObserver = null;

    private bool $ownsCallbacks = false;

    private string $outgoing = '';

    private bool $settled = false;

    private bool $started = false;

    private ?CancellationSubscription $subscription = null;

    private ?int $timer = null;

    /**
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Connection,?Throwable):void $onComplete
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Connection $connection,
        private readonly ClientRequest $request,
        callable $onHeaders,
        callable $onBody,
        callable $onComplete,
    ) {
        if ($connection->state() !== ConnectionState::OPEN || !$connection->isEncrypted()
            || $connection->negotiatedProtocol() !== 'h2'
            || $request->protocol->negotiate('h2', true) !== ProtocolVersion::HTTP_2) {
            throw new InvalidArgumentException('HTTP/2 exchange requires a live verified TLS h2 transport and approved protocol.');
        }

        $this->session = new Http2ClientSession($request, $onHeaders, $onBody);
        $this->frames = new FrameParser();
        $this->completion = Closure::fromCallable($onComplete);
    }

    /** Abort this connection-scoped request and release all callbacks. */
    public function close(): void
    {
        $this->finish(new RuntimeException('HTTP/2 exchange closed before response completion.'));
    }

    /** Bind to one open H2 socket and emit the protocol client preface. */
    public function start(): void
    {
        if ($this->started || $this->settled || !$this->connection->isPoolIdleSafe()) {
            throw new InvalidArgumentException('HTTP/2 exchange requires a fresh live socket.');
        }
        $this->started = true;
        $this->outgoing = $this->session->start();
        try {
            $this->connection->handoffCallbacks(
                $this,
                function (): void { $this->consume(); },
                function (): void { $this->pump(); },
                function (): void { $this->finish(new RuntimeException('HTTP/2 peer disconnected before END_STREAM.')); },
            );
            if ($this->settled) {
                return;
            }
            $this->ownsCallbacks = true;
            $this->closeObserver = $this->connection->registerCloseObserver(function (): void {
                $this->finish(new RuntimeException('HTTP/2 connection closed during response.'));
            });
            $this->armDeadline();
            $this->pump();
        } catch (Throwable $error) {
            $this->finish($error);
        }
    }

    private function armDeadline(): void
    {
        $token = $this->request->cancellation;
        if ($token?->isCancelled()) {
            throw new CancelledException($token->reason() ?? CancellationReason::HOST_CANCELLED);
        }
        $seconds = min(
            10.0,
            $this->request->deadline?->remainingSeconds() ?? INF,
            $token?->deadline()->remainingSeconds() ?? INF,
        );
        if ($seconds <= 0) {
            throw new RuntimeException('HTTP/2 response deadline has expired.');
        }
        $this->timer = $this->loop->delay($seconds, function (): void {
            $this->finish(new RuntimeException('HTTP/2 response deadline expired.'));
        });
        $this->subscription = $token?->onCancel(function () use ($token): void {
            $this->finish(new CancelledException($token->reason() ?? CancellationReason::HOST_CANCELLED));
        });
    }

    private function consume(): void
    {
        if ($this->settled) {
            return;
        }
        try {
            for ($turn = 0; $turn < 8 && !$this->settled; ++$turn) {
                $bytes = $this->connection->receivedBytes() > 0
                    ? $this->connection->read(8_192)
                    : '';
                foreach ($this->frames->push($bytes, 32) as $frame) {
                    $this->queue($this->session->receive($frame));
                    if ($this->session->done()) {
                        $this->finish(null);

                        return;
                    }
                }
                if ($this->connection->receivedBytes() === 0 && !$this->frames->hasCompleteFrame()) {
                    break;
                }
            }
            $this->pump();
            if ($this->connection->receivedBytes() > 0 || $this->frames->hasCompleteFrame()) {
                $this->loop->defer(function (): void { $this->consume(); });
            }
        } catch (Throwable $error) {
            $this->finish($error);
        }
    }

    private function finish(?Throwable $error): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->subscription?->unsubscribe();
        $this->subscription = null;
        if ($this->timer !== null) {
            $this->loop->cancel($this->timer);
            $this->timer = null;
        }
        if ($this->closeObserver !== null) {
            $this->connection->unregisterCloseObserver($this->closeObserver);
            $this->closeObserver = null;
        }
        if ($this->ownsCallbacks) {
            $this->connection->releaseCallbacks($this);
            $this->ownsCallbacks = false;
        }
        // Not yet an active HTTP/2 connection pool: never advertise reuse.
        $this->connection->abort();
        ($this->completion)(null, $error);
    }

    private function pump(): void
    {
        if ($this->settled || $this->outgoing === '') {
            return;
        }
        try {
            for ($chunks = 0; $chunks < 8 && $this->outgoing !== ''; ++$chunks) {
                $available = $this->connection->availableWriteBytes();
                if ($available <= 0) {
                    return;
                }
                $part = substr($this->outgoing, 0, min(8_192, $available));
                if (!$this->connection->write($part)->accepted()) {
                    throw new RuntimeException('HTTP/2 outbound socket exceeded its write budget.');
                }
                $this->outgoing = substr($this->outgoing, strlen($part));
            }
            if ($this->outgoing !== '' && !$this->connection->isWritePressured()) {
                $this->loop->defer(function (): void { $this->pump(); });
            }
        } catch (Throwable $error) {
            $this->finish($error);
        }
    }

    private function queue(string $wire): void
    {
        if ($wire === '') {
            return;
        }
        if (strlen($wire) > 131_072 - strlen($this->outgoing)) {
            throw new RuntimeException('HTTP/2 outbound control queue exceeded its memory budget.');
        }
        $this->outgoing .= $wire;
    }
}
