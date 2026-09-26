<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1;

use Closure;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http1\Enum\ParserState;
use Infocyph\Runwire\Http\Http1\Internal\ChunkSizeDecoder;
use Infocyph\Runwire\Http\Http1\Internal\Http1Input;
use Infocyph\Runwire\Http\Http1\Internal\Http1Syntax;
use Infocyph\Runwire\Http\Http1\Internal\ParseFailure;
use Infocyph\Runwire\Http\Http1\Internal\RequestHead;
use Infocyph\Runwire\Http\Http1\Internal\RequestHeadValidator;
use Infocyph\Runwire\Http\Http1\Internal\WebSocketUpgradeOwner;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\StreamingRequestBody;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\WebSocket\WebSocketOptions;
use Infocyph\Runwire\WebSocket\WebSocketSession;
use Throwable;

/**
 * Parses and serves HTTP/1.1 exchanges over a single network connection.
 */
final class Http1Connection
{
    private readonly ChunkSizeDecoder $chunkSizeDecoder;

    /** @var Closure(HttpRequest, ResponseWriterInterface): void */
    private readonly Closure $handler;

    private readonly Http1Input $input;

    private readonly RequestHeadValidator $requestHeadValidator;

    private readonly Http1Syntax $syntax;

    private readonly WebSocketUpgradeOwner $webSocketOwner;

    private ?StreamingRequestBody $body = null;

    private bool $bodyPressured = false;

    private int $bodyReceived = 0;

    private ?int $bodyTimer = null;

    private bool $closed = false;

    private bool $discardBody = false;

    private bool $draining = false;

    private int $headerBytes = 0;

    private int $headerCount = 0;

    /** @var list<HeaderField> */
    private array $headerFields = [];

    private ?int $headerTimer = null;

    private bool $keepAlive = true;

    private float $lastBodyProgressAt = 0.0;

    private string $method = '';

    private bool $pumping = false;

    private bool $pumpScheduled = false;

    private int $remainingBody = 0;

    private int $remainingChunk = 0;

    private int $requestCount = 0;

    private bool $responseCloseAfter = false;

    private bool $responseEnded = false;

    private ParserState $state = ParserState::REQUEST_LINE;

    private string $target = '';

    private int $trailerBytes = 0;

    private int $trailerCount = 0;

    /** @var list<HeaderField> */
    private array $trailerFields = [];

    private bool $waitingResponse = false;

    private ?Http1ResponseWriter $writer = null;

    /** @param callable(HttpRequest, ResponseWriterInterface): void $handler */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Connection $connection,
        private readonly Http1Limits $limits,
        callable $handler,
    ) {
        /** @var Closure(HttpRequest, ResponseWriterInterface): void $handlerClosure */
        $handlerClosure = Closure::fromCallable($handler);
        $this->handler = $handlerClosure;
        $this->requestHeadValidator = new RequestHeadValidator();
        $this->chunkSizeDecoder = new ChunkSizeDecoder();
        $this->input = new Http1Input($connection);
        $this->syntax = new Http1Syntax();
        $this->webSocketOwner = new WebSocketUpgradeOwner(
            $loop,
            $connection,
            $this->input,
            fn(): void => $this->prepareWebSocketHandoff(),
        );
        $connection->onData(function (): void {
            $this->pump();
        });
        $connection->onEof(function (): void {
            $this->handleEof();
        });
        $connection->onClose(function (): void {
            $this->cleanup();
        });
        $this->armHeaderTimer();
        if ($connection->receivedBytes() > 0) {
            $this->schedulePump();
        }
    }

    /**
     * Stop accepting further keep-alive work and drain the active exchange.
     */
    public function drain(): void
    {
        if ($this->closed || $this->draining) {
            return;
        }

        $this->draining = true;
        if ($this->webSocketOwner->drain()) {
            return;
        }

        $this->keepAlive = false;
        $this->responseCloseAfter = true;
        $this->writer?->forceCloseAfterResponse();

        if ($this->body === null && $this->writer === null) {
            $this->connection->closeGracefully();
        }
    }

    /**
     * Return the number of requests processed on this connection.
     */
    public function requestCount(): int
    {
        return $this->requestCount;
    }

    private static function errorPhrase(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            408 => 'Request Timeout',
            413 => 'Content Too Large',
            414 => 'URI Too Long',
            417 => 'Expectation Failed',
            431 => 'Request Header Fields Too Large',
            503 => 'Service Unavailable',
            default => 'Bad Request',
        };
    }

    private function armBodyTimer(): void
    {
        $this->lastBodyProgressAt = $this->loop->now();
        $this->scheduleBodyTimer($this->limits->bodyIdleTimeoutSeconds);
    }

    private function armHeaderTimer(): void
    {
        if ($this->headerTimer !== null) {
            return;
        }
        $this->headerTimer = $this->loop->delay($this->limits->headerTimeoutSeconds, function (): void {
            $this->headerTimer = null;
            if (!$this->closed && ($this->state === ParserState::REQUEST_LINE || $this->state === ParserState::HEADERS)) {
                $this->fail(408);
            }
        });
    }

    private function cancelTimer(?int $handle): void
    {
        if ($handle !== null) {
            $this->loop->cancel($handle);
        }
    }

    private function checkBodyDeadline(): void
    {
        if ($this->closed || $this->body === null || $this->body->ended()) {
            return;
        }

        $remaining = $this->limits->bodyIdleTimeoutSeconds
            - ($this->loop->now() - $this->lastBodyProgressAt);
        if ($remaining <= 0) {
            $this->fail(408);

            return;
        }

        $this->scheduleBodyTimer($remaining);
    }

    private function cleanup(): void
    {
        $this->body?->cancel();
        $this->closed = true;
        $this->cancelTimer($this->headerTimer);
        $this->cancelTimer($this->bodyTimer);
        $this->headerTimer = $this->bodyTimer = null;
    }

    private function configureRequestBody(RequestHead $head, StreamingRequestBody $body): void
    {
        if ($head->chunked) {
            $this->state = ParserState::CHUNK_SIZE;
            $this->armBodyTimer();

            return;
        }
        if ($head->contentLength > 0) {
            $this->remainingBody = $head->contentLength;
            $this->state = ParserState::FIXED_BODY;
            $this->armBodyTimer();

            return;
        }

        $body->finish();
        $this->state = ParserState::WAIT_RESPONSE;
    }

    /** @param callable(int): void $after */
    private function consumeBodyBytes(int $remaining, callable $after): bool
    {
        if ($this->input->availableBytes() === 0) {
            return false;
        }
        if (!$this->discardBody && $this->body !== null && $this->body->capacity() <= 0) {
            $this->bodyPressured = true;
            $this->syncReadPause();

            return false;
        }

        $capacity = $this->discardBody || $this->body === null ? 65_536 : $this->body->capacity();
        $length = min($remaining, 65_536, $capacity, $this->input->availableBytes());
        if ($length <= 0) {
            return false;
        }
        $chunk = $this->input->take($length);
        $bytes = strlen($chunk);
        $this->bodyReceived += $bytes;
        if ($this->bodyReceived > $this->limits->maxBodyBytes) {
            throw new ParseFailure(413, 'Request body exceeds configured limit.');
        }
        $this->lastBodyProgressAt = $this->loop->now();

        if (!$this->discardBody && $this->body !== null && !$this->body->push($chunk)) {
            $this->bodyPressured = true;
            $this->syncReadPause();
        }
        $after($bytes);

        return true;
    }

    private function consumeChunkCrlf(): bool
    {
        if ($this->input->availableBytes() < 2) {
            return false;
        }
        if ($this->input->take(2) !== "\r\n") {
            throw new ParseFailure(400, 'Chunk data is not followed by CRLF.');
        }
        $this->state = ParserState::CHUNK_SIZE;

        return true;
    }

    private function consumeChunkData(): bool
    {
        if ($this->remainingChunk === 0) {
            $this->state = ParserState::CHUNK_CRLF;

            return true;
        }

        return $this->consumeBodyBytes($this->remainingChunk, function (int $bytes): void {
            $this->remainingChunk -= $bytes;
            if ($this->remainingChunk === 0) {
                $this->state = ParserState::CHUNK_CRLF;
            }
        });
    }

    private function consumeFixedBody(): bool
    {
        if ($this->remainingBody === 0) {
            $this->finishBody();

            return true;
        }

        return $this->consumeBodyBytes($this->remainingBody, function (int $bytes): void {
            $this->remainingBody -= $bytes;
            if ($this->remainingBody === 0) {
                $this->finishBody();
            }
        });
    }

    private function createBody(): StreamingRequestBody
    {
        return new StreamingRequestBody(
            $this->limits->bodyLowWatermarkBytes,
            $this->limits->bodyHighWatermarkBytes,
            $this->limits->maxPendingBodyBytes,
            function (): void {
                $this->bodyPressured = false;
                $this->syncReadPause();
                $this->schedulePump();
            },
        );
    }

    private function createWriter(): Http1ResponseWriter
    {
        return new Http1ResponseWriter(
            $this->connection,
            $this->limits,
            $this->method,
            $this->keepAlive,
            function (bool $closeAfter): void {
                $this->handleResponseEnd($closeAfter);
            },
            fn(string $accept, ?string $subprotocol, WebSocketOptions $options): WebSocketSession => $this->webSocketOwner->upgrade(
                $accept,
                $subprotocol,
                $options,
                $this->body,
            ),
        );
    }

    private function dispatchRequest(): void
    {
        $headers = new Headers($this->headerFields);
        $head = $this->requestHeadValidator->validate(
            $headers,
            $this->limits->maxBodyBytes,
            $this->method,
            $this->target,
        );

        ++$this->requestCount;
        $this->keepAlive = !$this->draining
            && !$head->closeRequested
            && $this->requestCount < $this->limits->maxKeepAliveRequests;
        $this->bodyReceived = 0;
        $this->discardBody = false;
        $this->responseEnded = false;
        $this->responseCloseAfter = false;

        $body = $this->createBody();
        $writer = $this->createWriter();
        $this->body = $body;
        $this->writer = $writer;
        $this->configureRequestBody($head, $body);
        $this->sendContinueIfNeeded($head);

        $request = new HttpRequest(
            $this->method,
            $this->target,
            ProtocolVersion::HTTP_1_1,
            $headers,
            $body,
            $this->connection->peerAddress(),
            $this->connection->localAddress(),
            $this->connection->isEncrypted(),
        );

        try {
            ($this->handler)($request, $writer);
        } catch (Throwable $failure) {
            $body->cancel(false);
            $this->connection->abort(CloseReason::LOCAL_ABORT);

            throw $failure;
        }

        if ($this->body === $body && $body->ended() && !$this->responseEnded) {
            $this->waitingResponse = true;
            $this->state = ParserState::WAIT_RESPONSE;
            $this->syncReadPause();
        }
    }

    private function fail(int $status): void
    {
        if ($this->closed) {
            return;
        }
        $this->cancelTimer($this->headerTimer);
        $this->cancelTimer($this->bodyTimer);
        $this->headerTimer = $this->bodyTimer = null;
        $this->waitingResponse = false;
        $this->bodyPressured = false;
        $this->syncReadPause();
        $this->body?->cancel();

        if ($this->writer !== null && $this->writer->isStarted()) {
            $this->connection->abort(CloseReason::PROTOCOL_ERROR);

            return;
        }
        $wire = sprintf(
            "HTTP/1.1 %d %s\r\nconnection: close\r\ncontent-length: 0\r\n\r\n",
            $status,
            self::errorPhrase($status),
        );
        $result = $this->connection->write($wire);
        if ($result->state === WriteState::REJECTED_LIMIT || $result->state === WriteState::CLOSED) {
            $this->connection->abort(CloseReason::PROTOCOL_ERROR);

            return;
        }
        $this->connection->closeGracefully();
    }

    private function finishBody(?Headers $trailers = null): void
    {
        $this->cancelTimer($this->bodyTimer);
        $this->bodyTimer = null;
        $body = $this->body;
        $body?->finish($trailers);
        if ($this->body !== $body) {
            return;
        }
        $this->bodyPressured = false;
        $this->syncReadPause();

        if ($this->responseEnded) {
            if ($this->responseCloseAfter) {
                $this->connection->closeGracefully();
            } else {
                $this->resetExchange();
            }

            return;
        }
        $this->waitingResponse = true;
        $this->state = ParserState::WAIT_RESPONSE;
        $this->syncReadPause();
    }

    private function handleEof(): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->state !== ParserState::REQUEST_LINE || $this->input->availableBytes() > 0) {
            $this->pump();
        }
        if (!$this->closed && ($this->state !== ParserState::REQUEST_LINE || $this->input->bufferedBytes() > 0)) {
            $this->fail(400);
        }
    }

    private function handleResponseEnd(bool $closeAfter): void
    {
        if ($this->closed) {
            return;
        }
        $this->responseEnded = true;
        $this->responseCloseAfter = $closeAfter;
        if ($this->body === null || !$this->body->ended()) {
            $this->discardBody = true;
            $this->body?->discardBuffered();
            $this->bodyPressured = false;
            $this->syncReadPause();
            $this->schedulePump();

            return;
        }
        if ($closeAfter) {
            $this->connection->closeGracefully();

            return;
        }
        $this->resetExchange();
    }

    private function parseChunkSizeLine(): bool
    {
        $line = $this->input->readLine($this->limits->maxChunkLineBytes, 400);
        if ($line === null) {
            return false;
        }
        $size = $this->chunkSizeDecoder->decode(
            $line,
            $this->limits->maxBodyBytes,
            $this->bodyReceived,
        );
        if ($size === 0) {
            $this->trailerFields = [];
            $this->trailerBytes = 0;
            $this->trailerCount = 0;
            $this->state = ParserState::TRAILERS;

            return true;
        }
        $this->remainingChunk = $size;
        $this->state = ParserState::CHUNK_DATA;

        return true;
    }

    private function parseHeaderLine(): bool
    {
        $line = $this->input->readLine($this->limits->maxHeaderLineBytes, 431);
        if ($line === null) {
            return false;
        }
        $this->headerBytes += strlen($line) + 2;
        if ($this->headerBytes > $this->limits->maxHeaderBytes) {
            throw new ParseFailure(431, 'Request headers exceed configured byte limit.');
        }
        if ($line === '') {
            $this->cancelTimer($this->headerTimer);
            $this->headerTimer = null;
            $this->dispatchRequest();

            return true;
        }
        ++$this->headerCount;
        if ($this->headerCount > $this->limits->maxHeaderCount) {
            throw new ParseFailure(431, 'Request header count exceeds configured limit.');
        }
        $this->headerFields[] = $this->syntax->headerField($line);

        return true;
    }

    private function parseRequestLine(): bool
    {
        if ($this->input->availableBytes() === 0) {
            return false;
        }
        $this->armHeaderTimer();
        $line = $this->input->readLine($this->limits->maxRequestLineBytes, 414);
        if ($line === null) {
            return false;
        }
        [$this->method, $this->target] = $this->syntax->requestLine($line);
        $this->state = ParserState::HEADERS;

        return true;
    }

    private function parseTrailerLine(): bool
    {
        $line = $this->input->readLine($this->limits->maxHeaderLineBytes, 431);
        if ($line === null) {
            return false;
        }
        $this->trailerBytes += strlen($line) + 2;
        if ($this->trailerBytes > $this->limits->maxHeaderBytes) {
            throw new ParseFailure(431, 'Request trailers exceed configured byte limit.');
        }
        if ($line === '') {
            $this->finishBody(new Headers($this->trailerFields));

            return true;
        }
        ++$this->trailerCount;
        if ($this->trailerCount > $this->limits->maxHeaderCount) {
            throw new ParseFailure(431, 'Request trailer count exceeds configured limit.');
        }
        $this->trailerFields[] = $this->syntax->trailerField($line);

        return true;
    }

    private function prepareWebSocketHandoff(): void
    {
        $this->cancelTimer($this->headerTimer);
        $this->cancelTimer($this->bodyTimer);
        $this->headerTimer = $this->bodyTimer = null;
        $this->waitingResponse = false;
        $this->bodyPressured = false;
        $this->keepAlive = false;
        $this->state = ParserState::WAIT_RESPONSE;
        $this->connection->resumeReads();
        $this->body = null;
        $this->writer = null;
    }

    private function pump(): void
    {
        if ($this->closed || $this->pumping || $this->webSocketOwner->active()) {
            return;
        }
        $this->pumping = true;
        $this->pumpScheduled = false;

        try {
            for ($steps = 0; $steps < $this->limits->maxParserStepsPerTick; ++$steps) {
                if (!$this->step()) {
                    return;
                }
            }
            $this->schedulePump();
        } catch (ParseFailure $failure) {
            $this->fail($failure->status);
        } catch (Throwable $throwable) {
            $this->connection->abort(CloseReason::PROTOCOL_ERROR);

            throw $throwable;
        } finally {
            $this->pumping = false;
        }
    }

    private function resetExchange(): void
    {
        if ($this->draining) {
            $this->connection->closeGracefully();

            return;
        }

        $this->state = ParserState::REQUEST_LINE;
        $this->method = '';
        $this->target = '';
        $this->headerFields = [];
        $this->headerBytes = 0;
        $this->headerCount = 0;
        $this->trailerFields = [];
        $this->trailerBytes = 0;
        $this->trailerCount = 0;
        $this->remainingBody = 0;
        $this->remainingChunk = 0;
        $this->bodyReceived = 0;
        $this->discardBody = false;
        $this->bodyPressured = false;
        $this->waitingResponse = false;
        $this->responseEnded = false;
        $this->responseCloseAfter = false;
        $this->body = null;
        $this->writer = null;
        $this->syncReadPause();
        $this->armHeaderTimer();
        if ($this->input->availableBytes() > 0) {
            $this->schedulePump();
        }
    }

    private function scheduleBodyTimer(float $delay): void
    {
        $this->bodyTimer = $this->loop->delay($delay, function (): void {
            $this->bodyTimer = null;
            $this->checkBodyDeadline();
        });
    }

    private function schedulePump(): void
    {
        if ($this->closed || $this->pumpScheduled) {
            return;
        }
        $this->pumpScheduled = true;
        $this->loop->defer(function (): void {
            $this->pumpScheduled = false;
            $this->pump();
        });
    }

    private function sendContinueIfNeeded(RequestHead $head): void
    {
        if (!$head->expectContinue || (!$head->chunked && $head->contentLength === 0)) {
            return;
        }
        $result = $this->connection->write("HTTP/1.1 100 Continue\r\n\r\n");
        if (!$result->accepted()) {
            throw new ParseFailure(503, 'Unable to queue 100 Continue response.');
        }
    }

    private function step(): bool
    {
        return match ($this->state) {
            ParserState::REQUEST_LINE => $this->parseRequestLine(),
            ParserState::HEADERS => $this->parseHeaderLine(),
            ParserState::FIXED_BODY => $this->consumeFixedBody(),
            ParserState::CHUNK_SIZE => $this->parseChunkSizeLine(),
            ParserState::CHUNK_DATA => $this->consumeChunkData(),
            ParserState::CHUNK_CRLF => $this->consumeChunkCrlf(),
            ParserState::TRAILERS => $this->parseTrailerLine(),
            ParserState::WAIT_RESPONSE => false,
        };
    }

    private function syncReadPause(): void
    {
        if ($this->bodyPressured || $this->waitingResponse) {
            $this->connection->pauseReads();
        } else {
            $this->connection->resumeReads();
        }
    }
}
