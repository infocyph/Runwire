<?php

declare(strict_types=1);

namespace Infocyph\Runwire\WebSocket;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\Internal\ByteBudget;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\WebSocket\Internal\WebSocketFrame;
use Infocyph\Runwire\WebSocket\Internal\WebSocketFrameParser;
use Infocyph\Runwire\WebSocket\Internal\WebSocketProtocolException;
use Infocyph\Runwire\WebSocket\Internal\WebSocketWireCodec;
use InvalidArgumentException;
use OverflowException;
use Throwable;

/**
 * Owns one bounded native RFC 6455 WebSocket connection after HTTP/1 upgrade.
 */
final class WebSocketSession
{
    private const int MAX_DRAIN_OBSERVERS = 16;

    private readonly ?ByteBudget $budget;

    private readonly Connection $connection;

    private readonly WebSocketFrameParser $parser;

    private readonly ?string $selectedSubprotocol;

    /** @var Closure(self, int, string): void|null */
    private ?Closure $closeCallback = null;

    private int $closeCode = 1006;

    private string $closeReason = '';

    private bool $closed = false;

    private bool $closeSent = false;

    private ?int $closeTimer = null;

    private bool $closing = false;

    /** @var list<Closure(self): void> */
    private array $drainCallbacks = [];

    private int $fragmentBudgetBytes = 0;

    private string $fragmentBuffer = '';

    private ?int $fragmentOpcode = null;

    private ?int $heartbeatTimer;

    private float $lastActivityAt;

    /** @var Closure(self, WebSocketMessage): void|null */
    private ?Closure $messageCallback = null;

    private bool $peerCloseReceived = false;

    private bool $pumpScheduled = false;

    /**
     * @internal WebSocket sessions are created by the HTTP/1 upgrade owner.
     */
    public function __construct(
        private readonly LoopInterface $loop,
        Connection $connection,
        private readonly WebSocketOptions $options = new WebSocketOptions(),
        ?string $selectedSubprotocol = null,
        string $initialBytes = '',
    ) {
        $this->budget = $connection->bufferBudget();
        $this->connection = $connection;
        $this->parser = new WebSocketFrameParser($this->options, $this->budget);
        $this->selectedSubprotocol = $selectedSubprotocol;
        $this->lastActivityAt = $loop->now();

        $connection->handoffCallbacks(
            $this,
            function (): void {
                $this->schedulePump();
            },
            function (): void {
                $this->notifyDrain();
            },
            function (): void {
                $this->handleEof();
            },
        );
        $connection->onClose(function (): void {
            $this->finishClosed();
        });

        $this->heartbeatTimer = $loop->repeat(
            $options->heartbeatIntervalSeconds,
            function (): void {
                $this->heartbeat();
            },
        );

        if ($initialBytes !== '') {
            $this->parser->append($initialBytes);
            $this->schedulePump();
        } elseif ($connection->receivedBytes() > 0) {
            $this->schedulePump();
        }
        if ($connection->peerReadClosed()) {
            $loop->defer(function (): void {
                $this->handleEof();
            });
        }
    }

    /**
     * Begin a standards-compliant close handshake.
     */
    public function close(int $code = 1000, string $reason = ''): WriteResult
    {
        if ($this->closed) {
            return new WriteResult(WriteState::CLOSED, $this->connection->pendingWriteBytes());
        }
        WebSocketWireCodec::assertCloseCode($code);
        WebSocketWireCodec::assertUtf8($reason, 'WebSocket close reason');
        if (strlen($reason) > 123) {
            throw new InvalidArgumentException('WebSocket close reason cannot exceed 123 bytes.');
        }
        if ($this->closeSent) {
            return new WriteResult(
                $this->connection->isWritePressured() ? WriteState::PRESSURED : WriteState::ACCEPTED,
                $this->connection->pendingWriteBytes(),
            );
        }

        return $this->sendClosePayload(pack('n', $code) . $reason, $code, $reason);
    }

    /**
     * Ask an upgraded session to stop accepting application work during worker drain.
     */
    public function drain(): void
    {
        if ($this->closed || $this->closing) {
            return;
        }

        $result = $this->close(1001, 'server shutdown');
        if (!$result->accepted()) {
            $this->connection->abort(CloseReason::LOCAL_ABORT);
        }
    }

    /**
     * Determine whether the session is fully closed.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Determine whether a close handshake is in progress.
     */
    public function isClosing(): bool
    {
        return $this->closing;
    }

    /**
     * Register one close observer.
     *
     * @param callable(self, int, string): void $callback
     */
    public function onClose(callable $callback): self
    {
        $consumer = Closure::fromCallable($callback);
        if ($this->closed) {
            $consumer($this, $this->closeCode, $this->closeReason);

            return $this;
        }

        $this->closeCallback = $consumer;

        return $this;
    }

    /**
     * Register a callback for the next write-pressure relief event.
     *
     * @param callable(self): void $callback
     */
    public function onDrain(callable $callback): self
    {
        if (count($this->drainCallbacks) >= self::MAX_DRAIN_OBSERVERS) {
            throw new OverflowException('WebSocket drain observer limit exceeded.');
        }

        $this->drainCallbacks[] = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Register the message consumer for fully reassembled text and binary messages.
     *
     * @param callable(self, WebSocketMessage): void $callback
     */
    public function onMessage(callable $callback): self
    {
        $this->messageCallback = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Send a WebSocket ping control frame.
     */
    public function ping(string $payload = ''): WriteResult
    {
        if (strlen($payload) > 125) {
            throw new InvalidArgumentException('WebSocket ping payload cannot exceed 125 bytes.');
        }

        return $this->sendApplicationFrame(0x9, $payload);
    }

    /**
     * Send one bounded binary message.
     */
    public function sendBinary(string $payload): WriteResult
    {
        $this->assertOutboundMessage($payload, false);

        return $this->sendApplicationFrame(0x2, $payload);
    }

    /**
     * Send one bounded UTF-8 text message.
     */
    public function sendText(string $payload): WriteResult
    {
        $this->assertOutboundMessage($payload, true);

        return $this->sendApplicationFrame(0x1, $payload);
    }

    /**
     * Return the negotiated WebSocket subprotocol.
     */
    public function subprotocol(): ?string
    {
        return $this->selectedSubprotocol;
    }

    private function appendFragment(string $payload): void
    {
        $length = strlen($payload);
        if (strlen($this->fragmentBuffer) + $length > $this->options->maxMessageBytes) {
            throw new WebSocketProtocolException(1009, 'Fragmented WebSocket message exceeds the configured ceiling.');
        }
        if ($this->budget !== null && !$this->budget->reserve($length)) {
            throw new WebSocketProtocolException(1009, 'WebSocket worker byte budget is exhausted by fragmented input.');
        }

        $this->fragmentBudgetBytes += $length;
        $this->fragmentBuffer .= $payload;
    }

    private function assertOutboundMessage(string $payload, bool $text): void
    {
        if (strlen($payload) > $this->options->maxFramePayloadBytes) {
            throw new InvalidArgumentException('Outbound WebSocket message exceeds the configured frame ceiling.');
        }
        if ($text) {
            WebSocketWireCodec::assertUtf8($payload, 'WebSocket text message');
        }
    }

    private function cancelTimer(?int $timer): void
    {
        if ($timer !== null) {
            $this->loop->cancel($timer);
        }
    }

    private function clearFragment(): void
    {
        if ($this->fragmentBudgetBytes > 0) {
            $this->budget?->release($this->fragmentBudgetBytes);
        }
        $this->fragmentBudgetBytes = 0;
        $this->fragmentBuffer = '';
        $this->fragmentOpcode = null;
    }

    private function deliver(int $opcode, string $payload): void
    {
        if ($opcode === 0x1 && $payload !== '' && preg_match('//u', $payload) !== 1) {
            throw new WebSocketProtocolException(1007, 'WebSocket text message contains invalid UTF-8.');
        }
        if (strlen($payload) > $this->options->maxMessageBytes) {
            throw new WebSocketProtocolException(1009, 'WebSocket message exceeds the configured ceiling.');
        }
        if ($this->messageCallback === null) {
            throw new WebSocketProtocolException(1008, 'WebSocket message arrived before a message handler was registered.');
        }

        try {
            ($this->messageCallback)($this, new WebSocketMessage($payload, $opcode === 0x2));
        } catch (Throwable) {
            $this->protocolFailure(1011, 'message handler failed');
        }
    }

    private function dispatchFrame(WebSocketFrame $frame): void
    {
        $this->lastActivityAt = $this->loop->now();

        match ($frame->opcode) {
            0x0 => $this->handleContinuation($frame),
            0x1, 0x2 => $this->handleDataFrame($frame),
            0x8 => $this->handlePeerClose($frame->payload),
            0x9 => $this->handlePing($frame->payload),
            0xA => null,
            default => throw new WebSocketProtocolException(1002, 'Unsupported WebSocket opcode.'),
        };
    }

    private function finishClosed(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->closing = true;
        $this->cancelTimer($this->heartbeatTimer);
        $this->cancelTimer($this->closeTimer);
        $this->heartbeatTimer = $this->closeTimer = null;
        $this->clearFragment();

        try {
            $this->connection->releaseCallbacks($this);
        } catch (Throwable) {
            // Connection teardown owns callback release if the transport already discarded them.
        }

        $callback = $this->closeCallback;
        $this->closeCallback = null;
        $this->drainCallbacks = [];
        if ($callback !== null) {
            try {
                $callback($this, $this->closeCode, $this->closeReason);
            } catch (Throwable) {
                // Close observers are isolated from transport teardown.
            }
        }
    }

    private function handleContinuation(WebSocketFrame $frame): void
    {
        if ($this->fragmentOpcode === null) {
            throw new WebSocketProtocolException(1002, 'Unexpected WebSocket continuation frame.');
        }

        $this->appendFragment($frame->payload);
        if (!$frame->fin) {
            return;
        }

        $opcode = $this->fragmentOpcode;
        if ($opcode === null) {
            throw new WebSocketProtocolException(1002, 'Unexpected WebSocket continuation frame.');
        }

        $payload = $this->fragmentBuffer;
        $this->clearFragment();
        $this->deliver($opcode, $payload);
    }

    private function handleDataFrame(WebSocketFrame $frame): void
    {
        if ($this->fragmentOpcode !== null) {
            throw new WebSocketProtocolException(1002, 'New WebSocket data frame arrived during fragmentation.');
        }

        if ($frame->fin) {
            $this->deliver($frame->opcode, $frame->payload);

            return;
        }

        $this->fragmentOpcode = $frame->opcode;
        $this->appendFragment($frame->payload);
    }

    private function handleEof(): void
    {
        if ($this->closed) {
            return;
        }

        if (!$this->peerCloseReceived) {
            $this->closeCode = 1006;
            $this->closeReason = 'peer closed without WebSocket close frame';
        }
        $this->closing = true;
    }

    private function handlePeerClose(string $payload): void
    {
        [$code, $reason] = WebSocketWireCodec::parseClosePayload($payload);
        $this->peerCloseReceived = true;
        $this->closeCode = $code;
        $this->closeReason = $reason;
        $this->closing = true;

        if (!$this->closeSent) {
            $result = $this->sendClosePayload($payload, $code, $reason);
            if (!$result->accepted()) {
                $this->connection->abort(CloseReason::PROTOCOL_ERROR);

                return;
            }
        }

        $this->connection->closeGracefully();
    }

    private function handlePing(string $payload): void
    {
        $result = $this->sendFrame(0xA, $payload, true);
        if (!$result->accepted()) {
            $this->connection->abort(CloseReason::WRITE_ERROR);
        }
    }

    private function heartbeat(): void
    {
        if ($this->closed || $this->closing) {
            return;
        }

        if ($this->loop->now() - $this->lastActivityAt >= $this->options->idleTimeoutSeconds) {
            $result = $this->close(1001, 'idle timeout');
            if (!$result->accepted()) {
                $this->connection->abort(CloseReason::LOCAL_ABORT);
            }

            return;
        }

        $result = $this->ping();
        if (!$result->accepted()) {
            $this->connection->abort(CloseReason::WRITE_ERROR);
        }
    }

    private function notifyDrain(): void
    {
        if ($this->closed) {
            return;
        }

        $this->connection->resumeReads();
        $this->schedulePump();
        if ($this->drainCallbacks === []) {
            return;
        }

        $callbacks = $this->drainCallbacks;
        $this->drainCallbacks = [];
        foreach ($callbacks as $callback) {
            try {
                $callback($this);
            } catch (Throwable) {
                $this->protocolFailure(1011, 'drain handler failed');

                return;
            }
        }
    }

    private function protocolFailure(int $code, string $reason): void
    {
        if ($this->closed) {
            return;
        }

        $reason = substr($reason, 0, 123);
        try {
            $result = $this->close($code, $reason);
        } catch (Throwable) {
            $this->connection->abort(CloseReason::PROTOCOL_ERROR);

            return;
        }

        if (!$result->accepted()) {
            $this->connection->abort(CloseReason::PROTOCOL_ERROR);
        }
    }

    private function pump(): void
    {
        $this->pumpScheduled = false;
        if ($this->closed) {
            return;
        }

        try {
            $available = $this->connection->receivedBytes();
            if ($available > 0) {
                $this->parser->append($this->connection->read(min(
                    $available,
                    $this->options->maxReadBytesPerTurn,
                )));
            }

            $processed = 0;
            while ($processed < $this->options->maxFramesPerTurn && !$this->connection->isWritePressured()) {
                $frames = $this->parser->parse(1);
                if ($frames === []) {
                    break;
                }

                $this->dispatchFrame($frames[0]);
                ++$processed;
                if ($this->closed) {
                    return;
                }
            }

            if (
                !$this->connection->isWritePressured()
                && (
                    $this->connection->receivedBytes() > 0
                    || $processed === $this->options->maxFramesPerTurn
                )
            ) {
                $this->schedulePump();
            }
        } catch (WebSocketProtocolException $error) {
            $this->protocolFailure($error->closeCode, $error->getMessage());
        }
    }

    private function schedulePump(): void
    {
        if ($this->closed || $this->pumpScheduled) {
            return;
        }

        $this->pumpScheduled = true;
        $this->loop->defer(function (): void {
            $this->pump();
        });
    }

    private function sendApplicationFrame(int $opcode, string $payload): WriteResult
    {
        if ($this->closed || $this->closing) {
            return new WriteResult(WriteState::CLOSED, $this->connection->pendingWriteBytes());
        }

        return $this->sendFrame($opcode, $payload, false);
    }

    private function sendClosePayload(string $payload, int $code, string $reason): WriteResult
    {
        $this->closing = true;
        $this->closeSent = true;
        $this->closeCode = $code;
        $this->closeReason = $reason;

        $result = $this->sendFrame(0x8, $payload, true);
        if (!$result->accepted()) {
            return $result;
        }

        $this->closeTimer ??= $this->loop->delay(
            $this->options->closeTimeoutSeconds,
            function (): void {
                if (!$this->closed) {
                    $this->connection->abort(CloseReason::LOCAL_ABORT);
                }
            },
        );

        if ($this->peerCloseReceived) {
            $this->connection->closeGracefully();
        }

        return $result;
    }

    private function sendFrame(int $opcode, string $payload, bool $allowClosing): WriteResult
    {
        if ($this->closed || (!$allowClosing && $this->closing)) {
            return new WriteResult(WriteState::CLOSED, $this->connection->pendingWriteBytes());
        }

        $result = $this->connection->write(WebSocketWireCodec::frame($opcode, $payload));
        if ($result->pressured()) {
            $this->connection->pauseReads();
        }

        return $result;
    }
}
