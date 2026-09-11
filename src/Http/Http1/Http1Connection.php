<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1;

use Closure;
use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Http1\Internal\ChunkSizeDecoder;
use Infocyph\Runwire\Http\Http1\Internal\ParseFailure;
use Infocyph\Runwire\Http\Http1\Internal\ParserState;
use Infocyph\Runwire\Http\Http1\Internal\RequestHeadValidator;
use Infocyph\Runwire\Http\Internal\StreamingRequestBody;
use Infocyph\Runwire\Http\ProtocolVersion;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\CloseReason;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\WriteState;
use Throwable;

final class Http1Connection
{
    private ParserState $state = ParserState::REQUEST_LINE;
    private string $buffer = '';
    private string $method = '';
    private string $target = '';

    /** @var list<HeaderField> */
    private array $headerFields = [];
    private int $headerBytes = 0;
    private int $headerCount = 0;

    /** @var list<HeaderField> */
    private array $trailerFields = [];
    private int $trailerBytes = 0;
    private int $trailerCount = 0;

    private int $remainingBody = 0;
    private int $remainingChunk = 0;
    private int $bodyReceived = 0;
    private int $requestCount = 0;
    private bool $keepAlive = true;
    private bool $discardBody = false;
    private bool $bodyPressured = false;
    private bool $waitingResponse = false;
    private bool $responseEnded = false;
    private bool $responseCloseAfter = false;
    private bool $pumping = false;
    private bool $pumpScheduled = false;
    private bool $closed = false;
    private ?int $headerTimer = null;
    private ?int $bodyTimer = null;
    private float $lastBodyProgressAt = 0.0;
    private ?StreamingRequestBody $body = null;
    private ?Http1ResponseWriter $writer = null;
    private readonly RequestHeadValidator $requestHeadValidator;
    private readonly ChunkSizeDecoder $chunkSizeDecoder;

    /** @var Closure(HttpRequest, ResponseWriterInterface): void */
    private readonly Closure $handler;

    /** @param callable(HttpRequest, ResponseWriterInterface): void $handler */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Connection $connection,
        private readonly Http1Limits $limits,
        callable $handler,
    ) {
        $this->handler = Closure::fromCallable($handler);
        $this->requestHeadValidator = new RequestHeadValidator();
        $this->chunkSizeDecoder = new ChunkSizeDecoder();
        $connection->onData(function (): void { $this->pump(); });
        $connection->onEof(function (): void { $this->handleEof(); });
        $connection->onClose(function (): void { $this->cleanup(); });
        if ($connection->receivedBytes() > 0) {
            $this->schedulePump();
        }
    }

    public function requestCount(): int
    {
        return $this->requestCount;
    }

    private function pump(): void
    {
        if ($this->closed || $this->pumping) {
            return;
        }
        $this->pumping = true;
        $this->pumpScheduled = false;

        try {
            for ($steps = 0; $steps < $this->limits->maxParserStepsPerTick && !$this->closed; ++$steps) {
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

    private function parseRequestLine(): bool
    {
        if ($this->buffer === '' && $this->connection->receivedBytes() === 0) {
            return false;
        }
        $this->armHeaderTimer();
        $line = $this->readLine($this->limits->maxRequestLineBytes, 414);
        if ($line === null) {
            return false;
        }
        if (preg_match("/^([!#$%&'*+.^_`|~0-9A-Za-z-]+) ([^\\x00-\\x20\\x7F]+) HTTP\\/1\\.1$/D", $line, $matches) !== 1) {
            throw new ParseFailure(400, 'Malformed HTTP/1.1 request line.');
        }
        $this->method = $matches[1];
        $this->target = $matches[2];
        if (str_contains($this->target, '#')) {
            throw new ParseFailure(400, 'HTTP request-target must not contain a fragment.');
        }
        $this->state = ParserState::HEADERS;
        return true;
    }

    private function parseHeaderLine(): bool
    {
        $line = $this->readLine($this->limits->maxHeaderLineBytes, 431);
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
        if ($line[0] === ' ' || $line[0] === "\t") {
            throw new ParseFailure(400, 'Obsolete folded HTTP headers are rejected.');
        }
        $colon = strpos($line, ':');
        if ($colon === false || $colon === 0) {
            throw new ParseFailure(400, 'Malformed HTTP header line.');
        }
        ++$this->headerCount;
        if ($this->headerCount > $this->limits->maxHeaderCount) {
            throw new ParseFailure(431, 'Request header count exceeds configured limit.');
        }

        try {
            $this->headerFields[] = new HeaderField(
                substr($line, 0, $colon),
                trim(substr($line, $colon + 1), " \t"),
            );
        } catch (\InvalidArgumentException $exception) {
            throw new ParseFailure(400, $exception->getMessage());
        }
        return true;
    }

    private function dispatchRequest(): void
    {
        $headers = new Headers($this->headerFields);
        $head = $this->requestHeadValidator->validate($headers, $this->limits->maxBodyBytes);

        ++$this->requestCount;
        $this->keepAlive = !$head->closeRequested
            && $this->requestCount < $this->limits->maxKeepAliveRequests;
        $this->bodyReceived = 0;
        $this->discardBody = false;
        $this->responseEnded = false;
        $this->responseCloseAfter = false;
        $this->body = new StreamingRequestBody(
            $this->limits->bodyLowWatermarkBytes,
            $this->limits->bodyHighWatermarkBytes,
            $this->limits->maxPendingBodyBytes,
            function (): void {
                $this->bodyPressured = false;
                $this->syncReadPause();
                $this->schedulePump();
            },
        );
        $this->writer = new Http1ResponseWriter(
            $this->connection,
            $this->limits,
            $this->method,
            $this->keepAlive,
            function (bool $closeAfter): void {
                $this->handleResponseEnd($closeAfter);
            },
        );

        if ($head->chunked) {
            $this->state = ParserState::CHUNK_SIZE;
            $this->armBodyTimer();
        } elseif ($head->contentLength > 0) {
            $this->remainingBody = $head->contentLength;
            $this->state = ParserState::FIXED_BODY;
            $this->armBodyTimer();
        } else {
            $this->body->finish();
            $this->state = ParserState::WAIT_RESPONSE;
        }

        if ($head->expectContinue && ($head->chunked || $head->contentLength > 0)) {
            $result = $this->connection->write("HTTP/1.1 100 Continue\r\n\r\n");
            if (!$result->accepted()) {
                throw new ParseFailure(503, 'Unable to queue 100 Continue response.');
            }
        }

        $request = new HttpRequest(
            $this->method,
            $this->target,
            ProtocolVersion::HTTP_1_1,
            $headers,
            $this->body,
            $this->connection->peerAddress(),
            $this->connection->localAddress(),
            $this->connection->isEncrypted(),
        );
        $body = $this->body;
        ($this->handler)($request, $this->writer);

        if ($this->body === $body && $body->ended() && !$this->responseEnded) {
            $this->waitingResponse = true;
            $this->state = ParserState::WAIT_RESPONSE;
            $this->syncReadPause();
        }
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

    private function parseChunkSizeLine(): bool
    {
        $line = $this->readLine($this->limits->maxChunkLineBytes, 400);
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

    private function consumeChunkCrlf(): bool
    {
        if ($this->availableBytes() < 2) {
            return false;
        }
        if ($this->takeBytes(2) !== "\r\n") {
            throw new ParseFailure(400, 'Chunk data is not followed by CRLF.');
        }
        $this->state = ParserState::CHUNK_SIZE;
        return true;
    }

    private function parseTrailerLine(): bool
    {
        $line = $this->readLine($this->limits->maxHeaderLineBytes, 431);
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
        if ($line[0] === ' ' || $line[0] === "\t") {
            throw new ParseFailure(400, 'Obsolete folded trailers are rejected.');
        }
        $colon = strpos($line, ':');
        if ($colon === false || $colon === 0) {
            throw new ParseFailure(400, 'Malformed trailer field.');
        }
        ++$this->trailerCount;
        if ($this->trailerCount > $this->limits->maxHeaderCount) {
            throw new ParseFailure(431, 'Request trailer count exceeds configured limit.');
        }
        try {
            $field = new HeaderField(substr($line, 0, $colon), trim(substr($line, $colon + 1), " \t"));
        } catch (\InvalidArgumentException $exception) {
            throw new ParseFailure(400, $exception->getMessage());
        }
        if (in_array($field->name, ['content-length', 'transfer-encoding', 'host'], true)) {
            throw new ParseFailure(400, 'Framing and routing fields are forbidden in trailers.');
        }
        $this->trailerFields[] = $field;
        return true;
    }

    /** @param callable(int): void $after */
    private function consumeBodyBytes(int $remaining, callable $after): bool
    {
        if ($this->availableBytes() === 0) {
            return false;
        }
        if (!$this->discardBody && $this->body !== null && $this->body->capacity() <= 0) {
            $this->bodyPressured = true;
            $this->syncReadPause();
            return false;
        }

        $capacity = $this->discardBody || $this->body === null ? 65_536 : $this->body->capacity();
        $length = min($remaining, 65_536, $capacity, $this->availableBytes());
        if ($length <= 0) {
            return false;
        }
        $chunk = $this->takeBytes($length);
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

    private function resetExchange(): void
    {
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
        if ($this->availableBytes() > 0) {
            $this->schedulePump();
        }
    }

    private function readLine(int $maxBytes, int $tooLongStatus): ?string
    {
        while (($position = strpos($this->buffer, "\r\n")) === false) {
            if (strlen($this->buffer) > $maxBytes) {
                throw new ParseFailure($tooLongStatus, 'HTTP line exceeds configured limit.');
            }
            $remaining = $maxBytes + 2 - strlen($this->buffer);
            if ($remaining <= 0 || $this->connection->receivedBytes() === 0) {
                return null;
            }
            $chunk = $this->connection->read(min(4_096, $remaining));
            if ($chunk === '') {
                return null;
            }
            $this->buffer .= $chunk;
        }

        if ($position > $maxBytes) {
            throw new ParseFailure($tooLongStatus, 'HTTP line exceeds configured limit.');
        }
        $line = substr($this->buffer, 0, $position);
        $this->buffer = (string) substr($this->buffer, $position + 2);
        return $line;
    }

    private function takeBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        $fromBuffer = min($bytes, strlen($this->buffer));
        $data = $fromBuffer > 0 ? substr($this->buffer, 0, $fromBuffer) : '';
        if ($fromBuffer > 0) {
            $this->buffer = (string) substr($this->buffer, $fromBuffer);
        }
        $remaining = $bytes - $fromBuffer;
        if ($remaining > 0) {
            $data .= $this->connection->read($remaining);
        }
        return $data;
    }

    private function availableBytes(): int
    {
        return strlen($this->buffer) + $this->connection->receivedBytes();
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

    private function armBodyTimer(): void
    {
        $this->lastBodyProgressAt = $this->loop->now();
        $this->scheduleBodyTimer($this->limits->bodyIdleTimeoutSeconds);
    }

    private function scheduleBodyTimer(float $delay): void
    {
        $this->bodyTimer = $this->loop->delay($delay, function (): void {
            $this->bodyTimer = null;
            $this->checkBodyDeadline();
        });
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

    private function handleEof(): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->state !== ParserState::REQUEST_LINE || $this->buffer !== '' || $this->connection->receivedBytes() > 0) {
            $this->pump();
        }
        if (!$this->closed && ($this->state !== ParserState::REQUEST_LINE || $this->buffer !== '')) {
            $this->fail(400);
        }
    }

    private function syncReadPause(): void
    {
        if ($this->bodyPressured || $this->waitingResponse) {
            $this->connection->pauseReads();
        } else {
            $this->connection->resumeReads();
        }
    }

    private function schedulePump(): void
    {
        if ($this->closed || $this->pumpScheduled || $this->pumping) {
            return;
        }
        $this->pumpScheduled = true;
        $this->loop->defer(function (): void {
            $this->pumpScheduled = false;
            $this->pump();
        });
    }

    private function cleanup(): void
    {
        $this->closed = true;
        $this->cancelTimer($this->headerTimer);
        $this->cancelTimer($this->bodyTimer);
        $this->headerTimer = $this->bodyTimer = null;
    }

    private function cancelTimer(?int $handle): void
    {
        if ($handle !== null) {
            $this->loop->cancel($handle);
        }
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
}
