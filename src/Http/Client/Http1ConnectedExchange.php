<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Experimental one-request HTTP/1.1 exchange on an already verified socket.
 *
 * No DNS, pooling, HTTP fallback or implicit redirect is performed here.
 * Upload production must call resumeUpload() when temporarily starved.
 */
final class Http1ConnectedExchange
{
    private readonly Closure $bodyCallback;

    private ?int $closeObserver = null;

    private readonly Closure $completion;

    private readonly Closure $headCallback;

    private bool $ownsCallbacks = false;

    private readonly Http1ClientResponseParser $parser;

    private bool $settled = false;

    private bool $started = false;

    private ?CancellationSubscription $subscription = null;

    private ?int $timer = null;

    private readonly Http1ClientRequestWriter $writer;

    /**
     * @param callable(int, \Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Connection, ?Throwable):void $onComplete
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Connection $connection,
        private readonly ClientRequest $request,
        callable $onHeaders,
        callable $onBody,
        callable $onComplete,
    ) {
        $parts = parse_url($request->uri);
        $tls = is_array($parts) && ($parts['scheme'] ?? null) === 'https';
        if ($connection->isEncrypted() !== $tls
            || $request->protocol->negotiate($connection->negotiatedProtocol(), $tls) !== ProtocolVersion::HTTP_1_1) {
            throw new InvalidArgumentException('HTTP/1.1 exchange requires the exact requested verified transport and ALPN.');
        }
        $this->headCallback = Closure::fromCallable($onHeaders);
        $this->bodyCallback = Closure::fromCallable($onBody);
        $this->completion = Closure::fromCallable($onComplete);
        $this->writer = new Http1ClientRequestWriter($request);
        $this->parser = new Http1ClientResponseParser(strcasecmp($request->method, 'HEAD') === 0);
    }

    /** Abort this logical request without returning a reusable connection. */
    public function close(): void
    {
        $this->finish(new RuntimeException('HTTP/1.1 exchange was closed before completion.'));
    }

    /** Resume an incremental request body source after it has produced new data. */
    public function resumeUpload(): void
    {
        if ($this->started && !$this->settled) {
            $this->pump();
        }
    }

    /** Exclusively attach the connection and begin bounded request forwarding. */
    public function start(): void
    {
        if ($this->started || $this->settled || !$this->connection->isPoolIdleSafe()) {
            throw new InvalidArgumentException('HTTP/1.1 exchange requires one fresh, unclaimed, open socket.');
        }
        $this->started = true;

        try {
            $this->connection->handoffCallbacks(
                $this,
                function (): void {
                    $this->consume();
                },
                function (): void {
                    $this->pump();
                },
                function (): void {
                    $this->endOfInput();
                },
            );
            $this->ownsCallbacks = true;
            $this->closeObserver = $this->connection->registerCloseObserver(function (): void {
                $this->finish(new RuntimeException('HTTP/1.1 connection closed during response.'));
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
        $timeout = min(
            10.0,
            $this->request->deadline?->remainingSeconds() ?? INF,
            $token?->deadline()->remainingSeconds() ?? INF,
        );
        if ($timeout <= 0) {
            throw new RuntimeException('HTTP/1.1 request deadline has expired.');
        }

        $this->timer = $this->loop->delay($timeout, function (): void {
            $this->finish(new RuntimeException('HTTP/1.1 response deadline expired.'));
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
            $remaining = 65_536;
            while ($remaining > 0 && $this->connection->receivedBytes() > 0 && !$this->settled) {
                $bytes = $this->connection->read(min($remaining, 8_192));
                $remaining -= strlen($bytes);
                $this->parser->push(
                    $bytes,
                    $this->headCallback,
                    $this->bodyCallback,
                    function (): void {
                        $this->finish(null);
                    },
                );
            }
            if (!$this->settled && $this->connection->receivedBytes() > 0) {
                $this->loop->defer(function (): void {
                    $this->consume();
                });
            }
        } catch (Throwable $error) {
            $this->finish($error);
        }
    }

    private function endOfInput(): void
    {
        if ($this->settled) {
            return;
        }

        try {
            $this->parser->finish(function (): void {
                $this->finish(null);
            });
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
        $canReuse = $error === null && $this->writer->done() && $this->parser->keepAlive()
            && $this->connection->state() === ConnectionState::OPEN;
        if ($this->ownsCallbacks) {
            $this->connection->releaseCallbacks($this);
            $this->ownsCallbacks = false;
        }
        $canReuse = $canReuse && $this->connection->isPoolIdleSafe();
        if (!$canReuse) {
            $this->connection->abort();
        }
        ($this->completion)($canReuse ? $this->connection : null, $error);
    }

    private function pump(): void
    {
        if ($this->settled || $this->writer->done()) {
            return;
        }
        try {
            $frames = 0;
            while ($frames++ < 8 && !$this->writer->done() && !$this->settled) {
                if ($this->connection->availableWriteBytes() < 8_192) {
                    break;
                }
                $bytes = $this->writer->next(4_096);
                if ($bytes === '') {
                    return;
                }
                if (!$this->connection->write($bytes)->accepted()) {
                    throw new RuntimeException('HTTP/1.1 request write exceeded the bounded socket capacity.');
                }
            }
            if (!$this->settled && !$this->writer->done() && !$this->connection->isWritePressured()) {
                $this->loop->defer(function (): void {
                    $this->pump();
                });
            }
        } catch (Throwable $error) {
            $this->finish($error);
        }
    }
}
