<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2;

use Closure;
use Infocyph\Runwire\Http\Http2\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;
use Infocyph\Runwire\Http\Http2\Internal\ConnectionError;
use Infocyph\Runwire\Http\Http2\Internal\ControlFrameBudget;
use Infocyph\Runwire\Http\Http2\Internal\FlowController;
use Infocyph\Runwire\Http\Http2\Internal\Http2Stream;
use Infocyph\Runwire\Http\Http2\Internal\RequestStreamLookup;
use Infocyph\Runwire\Http\Http2\Internal\RequestStreamProcessor;
use Infocyph\Runwire\Http\Http2\Internal\ResponseScheduler;
use Infocyph\Runwire\Http\Http2\Internal\StreamError;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\WriteState;
use Throwable;

/**
 * Manages one native HTTP/2 server connection and its stream lifecycle.
 */
final class Http2Connection
{
    public const string CLIENT_PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

    private readonly ControlFrameBudget $controlBudget;

    private readonly Encoder $encoder;

    private readonly FlowController $flow;

    /** @var Closure(HttpRequest, Http2ResponseWriter): void */
    private readonly Closure $handler;

    private readonly ResponseScheduler $output;

    private readonly FrameParser $parser;

    private readonly PeerSettings $peerSettings;

    private readonly RequestStreamProcessor $requests;

    private bool $closed = false;

    private bool $draining = false;

    private ?int $drainTimer = null;

    private bool $errorClosePending = false;

    private bool $firstFrame = true;

    private string $preface = '';

    private bool $prefaceComplete = false;

    private ?int $parserDeferred = null;

    private bool $settingsAcked = false;

    private ?int $settingsAckTimer;

    /** @param callable(HttpRequest, Http2ResponseWriter): void $handler */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Connection $connection,
        private readonly Http2Limits $limits,
        callable $handler,
    ) {
        /** @var Closure(HttpRequest, Http2ResponseWriter): void $handlerClosure */
        $handlerClosure = Closure::fromCallable($handler);
        $this->handler = $handlerClosure;
        $this->parser = new FrameParser($limits->maxInboundFrameSize);
        $this->peerSettings = new PeerSettings();
        $this->encoder = new Encoder($limits->maxDynamicTableBytes);
        $this->flow = new FlowController();
        $this->controlBudget = new ControlFrameBudget($loop, $limits->maxControlFramesPerSecond);

        $streamLookup = new RequestStreamLookup();
        $this->output = new ResponseScheduler(
            connection: $connection,
            limits: $limits,
            peerSettings: $this->peerSettings,
            encoder: $this->encoder,
            flow: $this->flow,
            streamLookup: fn(int $id): ?Http2Stream => $streamLookup->stream($id),
            cleanupClosed: fn(Http2Stream $stream) => $this->cleanupClosed($stream),
            readyCallback: fn() => $this->handleOutputReady(),
            activityCallback: fn(Http2Stream $stream) => $this->touch($stream),
        );

        $this->requests = new RequestStreamProcessor(
            loop: $loop,
            connection: $connection,
            limits: $limits,
            peerSettings: $this->peerSettings,
            flow: $this->flow,
            output: $this->output,
            handler: $this->handler,
            connectionFailure: fn(ErrorCode $code, string $message) => $this->failConnection($code, $message),
            streamFailure: fn(StreamError $error) => $this->handleStreamError($error),
            streamRemoved: fn() => $this->finishDrainIfReady(),
        );
        $streamLookup->attach($this->requests);

        $connection->onData(fn() => $this->pump());
        $connection->onEof(fn() => $this->handleEof());
        $connection->onClose(fn() => $this->cleanup());

        $this->output->sendControl(FrameWriter::settings(PeerSettings::local($limits)));
        $this->armSettingsAckTimer();
    }

    /**
     * Return the number of active request streams.
     */
    public function activeStreams(): int
    {
        return $this->requests->count();
    }

    /**
     * Begin graceful HTTP/2 connection draining.
     */
    public function drain(): void
    {
        if ($this->closed || $this->draining) {
            return;
        }

        $this->draining = true;
        $this->requests->setDraining(true);
        $this->output->sendControl(FrameWriter::goAway($this->requests->lastClientStreamId(), ErrorCode::NO_ERROR));
        $this->finishDrainIfReady();
        if ($this->closed) {
            return;
        }

        $this->drainTimer = $this->loop->delay($this->limits->drainTimeoutSeconds, function (): void {
            $this->drainTimer = null;
            if (!$this->closed) {
                $this->closed = true;
                $this->connection->abort(CloseReason::LOCAL_ABORT);
            }
        });
    }

    /**
     * Return the greatest client-initiated stream ID observed.
     */
    public function lastClientStreamId(): int
    {
        return $this->requests->lastClientStreamId();
    }

    private function acceptSettingsAck(Frame $frame): void
    {
        if ($frame->payload !== '') {
            throw new ConnectionError(ErrorCode::FRAME_SIZE_ERROR, 'HTTP/2 SETTINGS ACK must have an empty payload.');
        }
        $this->settingsAcked = true;
        $this->cancelTimer($this->settingsAckTimer);
        $this->settingsAckTimer = null;
    }

    private function armSettingsAckTimer(): void
    {
        $this->settingsAckTimer = $this->loop->delay($this->limits->headerBlockTimeoutSeconds, function (): void {
            $this->settingsAckTimer = null;
            if (!$this->closed && !$this->settingsAcked) {
                $this->failConnection(ErrorCode::SETTINGS_TIMEOUT, 'Peer did not acknowledge server SETTINGS in time.');
            }
        });
    }

    private function cancelRuntimeTimers(): void
    {
        $this->cancelTimer($this->settingsAckTimer);
        $this->cancelTimer($this->drainTimer);
        $this->cancelTimer($this->parserDeferred);
        $this->settingsAckTimer = null;
        $this->drainTimer = null;
        $this->parserDeferred = null;
    }

    private function cancelTimer(?int $timer): void
    {
        if ($timer !== null) {
            $this->loop->cancel($timer);
        }
    }

    private function cleanup(): void
    {
        $this->closed = true;
        $this->cancelRuntimeTimers();
        $this->requests->cleanup();
        $this->output->cleanup();
    }

    private function cleanupClosed(Http2Stream $stream): void
    {
        $this->requests->cleanupIfClosed($stream);
    }

    private function consumePreface(string $data): string
    {
        if ($this->prefaceComplete) {
            return $data;
        }

        $need = strlen(self::CLIENT_PREFACE) - strlen($this->preface);
        $take = min($need, strlen($data));
        $this->preface .= substr($data, 0, $take);
        if (!str_starts_with(self::CLIENT_PREFACE, $this->preface)) {
            $this->closed = true;
            $this->connection->abort(CloseReason::PROTOCOL_ERROR);

            return '';
        }
        if (strlen($this->preface) < strlen(self::CLIENT_PREFACE)) {
            return '';
        }

        $this->prefaceComplete = true;

        return substr($data, $take);
    }

    private function failConnection(ErrorCode $code, string $message): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->cancelRuntimeTimers();
        $this->requests->cleanup();

        if (!$this->prefaceComplete) {
            $this->connection->abort(CloseReason::PROTOCOL_ERROR);

            return;
        }

        $result = $this->output->sendControl(FrameWriter::goAway(
            $this->requests->lastClientStreamId(),
            $code,
            substr($message, 0, 128),
        ));
        if ($result->state === WriteState::CLOSED) {
            $this->connection->abort(CloseReason::PROTOCOL_ERROR);

            return;
        }
        if ($this->output->wireIdle()) {
            $this->connection->closeGracefully();

            return;
        }

        $this->errorClosePending = true;
    }

    private function finishDrainIfReady(): void
    {
        if (!$this->draining || $this->closed || $this->requests->count() !== 0 || !$this->output->wireIdle()) {
            return;
        }

        $this->cancelTimer($this->drainTimer);
        $this->drainTimer = null;
        $this->connection->closeGracefully();
    }

    private function handleEof(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->cleanup();
    }

    private function handleGoAway(Frame $frame): void
    {
        if ($frame->streamId !== 0) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'HTTP/2 GOAWAY must use stream zero.');
        }
        if (strlen($frame->payload) < 8) {
            throw new ConnectionError(ErrorCode::FRAME_SIZE_ERROR, 'HTTP/2 GOAWAY payload must contain at least eight bytes.');
        }
    }

    private function handleOutputReady(): void
    {
        if ($this->errorClosePending && $this->output->wireIdle()) {
            $this->errorClosePending = false;
            $this->connection->closeGracefully();

            return;
        }
        $this->finishDrainIfReady();
    }

    private function handlePing(Frame $frame): void
    {
        if ($frame->streamId !== 0) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'HTTP/2 PING must use stream zero.');
        }
        if (strlen($frame->payload) !== 8) {
            throw new ConnectionError(ErrorCode::FRAME_SIZE_ERROR, 'HTTP/2 PING payload must contain exactly eight bytes.');
        }
        if (!$frame->hasFlag(0x1)) {
            $this->output->sendControl(new Frame(FrameType::PING->value, 0x1, 0, $frame->payload));
        }
    }

    private function handlePriority(Frame $frame): void
    {
        if ($frame->streamId === 0) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'HTTP/2 PRIORITY requires a non-zero stream.');
        }
        if (strlen($frame->payload) !== 5) {
            throw new ConnectionError(ErrorCode::FRAME_SIZE_ERROR, 'HTTP/2 PRIORITY payload must contain exactly five bytes.');
        }

        /** @var array{1: int}|false $decoded */
        $decoded = unpack('N', substr($frame->payload, 0, 4));
        if ($decoded === false) {
            throw new ConnectionError(ErrorCode::FRAME_SIZE_ERROR, 'Unable to decode HTTP/2 PRIORITY dependency.');
        }
        $dependency = $decoded[1] & 0x7FFF_FFFF;
        if ($dependency === $frame->streamId) {
            throw new StreamError($frame->streamId, ErrorCode::PROTOCOL_ERROR, 'HTTP/2 stream cannot depend on itself.');
        }
    }

    private function handleReset(Frame $frame): void
    {
        if ($frame->streamId === 0) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'HTTP/2 RST_STREAM requires a non-zero stream.');
        }
        if (strlen($frame->payload) !== 4) {
            throw new ConnectionError(ErrorCode::FRAME_SIZE_ERROR, 'HTTP/2 RST_STREAM payload must contain exactly four bytes.');
        }

        $stream = $this->requests->stream($frame->streamId);
        if ($stream === null) {
            if ($frame->streamId > $this->requests->lastClientStreamId()) {
                throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'RST_STREAM received for an idle stream.');
            }

            return;
        }
        $this->requests->reset($stream);
    }

    private function handleSettings(Frame $frame): void
    {
        if ($frame->streamId !== 0) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'HTTP/2 SETTINGS must use stream zero.');
        }
        if ($frame->hasFlag(0x1)) {
            $this->acceptSettingsAck($frame);

            return;
        }

        $delta = $this->peerSettings->apply($frame->payload);
        $this->flow->applyInitialWindowDelta($this->requests->streams(), $delta);
        $this->encoder->setPeerMaxDynamicTableBytes(min(
            $this->peerSettings->headerTableSize,
            $this->limits->maxDynamicTableBytes,
        ));
        $this->output->sendControl(FrameWriter::settings(ack: true));
        $this->output->flush();
    }

    private function handleStreamError(StreamError $error): void
    {
        if ($this->closed) {
            return;
        }

        $this->output->sendControl(FrameWriter::rstStream($error->streamId, $error->errorCode));
        $stream = $this->requests->stream($error->streamId);
        if ($stream !== null) {
            $this->requests->reset($stream);
        }
    }

    private function handleWindowUpdate(Frame $frame): void
    {
        if (strlen($frame->payload) !== 4) {
            throw new ConnectionError(ErrorCode::FRAME_SIZE_ERROR, 'HTTP/2 WINDOW_UPDATE payload must be four bytes.');
        }

        /** @var array{1: int}|false $decoded */
        $decoded = unpack('N', $frame->payload);
        if ($decoded === false) {
            throw new ConnectionError(ErrorCode::FRAME_SIZE_ERROR, 'Unable to decode HTTP/2 WINDOW_UPDATE payload.');
        }
        $increment = $decoded[1] & 0x7FFF_FFFF;
        if ($frame->streamId === 0) {
            $this->flow->updateConnectionSend($increment);
            $this->output->flush();

            return;
        }

        $stream = $this->requests->stream($frame->streamId);
        if ($stream === null) {
            if ($frame->streamId > $this->requests->lastClientStreamId()) {
                throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'WINDOW_UPDATE received for an idle stream.');
            }

            return;
        }
        $this->flow->updateStreamSend($stream, $increment);
        $this->requests->touch($stream);
        $this->output->flush();
    }

    private function processBufferedFrames(string $data): void
    {
        foreach ($this->parser->push($data, $this->limits->maxFramesPerTurn) as $frame) {
            if ($this->closed) {
                return;
            }
            $this->processFrameSafely($frame);
        }

        if ($this->closed || !$this->parser->hasCompleteFrame() || $this->parserDeferred !== null) {
            return;
        }

        $this->parserDeferred = $this->loop->defer(function (): void {
            $this->parserDeferred = null;
            if (!$this->closed) {
                $this->processBufferedFrames('');
            }
        });
    }

    private function processFrame(Frame $frame): void
    {
        match ($frame->knownType()) {
            FrameType::DATA => $this->requests->handleData($frame),
            FrameType::HEADERS => $this->requests->handleHeaders($frame),
            FrameType::PRIORITY => $this->handlePriority($frame),
            FrameType::RST_STREAM => $this->handleReset($frame),
            FrameType::SETTINGS => $this->handleSettings($frame),
            FrameType::PUSH_PROMISE => throw new ConnectionError(
                ErrorCode::PROTOCOL_ERROR,
                'Clients cannot send PUSH_PROMISE to an HTTP/2 server.',
            ),
            FrameType::PING => $this->handlePing($frame),
            FrameType::GOAWAY => $this->handleGoAway($frame),
            FrameType::WINDOW_UPDATE => $this->handleWindowUpdate($frame),
            FrameType::CONTINUATION => $this->requests->handleContinuation($frame),
            null => null,
        };
    }

    private function processFrameSafely(Frame $frame): void
    {
        try {
            if ($this->requests->hasOpenHeaderBlock() && $frame->knownType() !== FrameType::CONTINUATION) {
                throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'HTTP/2 header block was interrupted before END_HEADERS.');
            }
            if ($this->firstFrame) {
                $this->validateFirstFrame($frame);
                $this->firstFrame = false;
            }

            $this->controlBudget->consume($frame->knownType());
            $this->processFrame($frame);
        } catch (StreamError $error) {
            $this->handleStreamError($error);
        }
    }

    private function pump(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $data = $this->connection->read();
            if ($data === '') {
                return;
            }

            $data = $this->consumePreface($data);
            if ($data === '' || !$this->prefaceComplete) {
                return;
            }

            $this->processBufferedFrames($data);
        } catch (ConnectionError $error) {
            $this->failConnection($error->errorCode, $error->getMessage());
        } catch (Throwable) {
            $this->failConnection(ErrorCode::INTERNAL_ERROR, 'Internal server error.');
        }
    }

    private function touch(Http2Stream $stream): void
    {
        $this->requests->touch($stream);
    }

    private function validateFirstFrame(Frame $frame): void
    {
        if ($frame->knownType() !== FrameType::SETTINGS || $frame->streamId !== 0 || $frame->hasFlag(0x1)) {
            throw new ConnectionError(
                ErrorCode::PROTOCOL_ERROR,
                'First peer HTTP/2 frame must be non-ACK SETTINGS on stream zero.',
            );
        }
    }
}
