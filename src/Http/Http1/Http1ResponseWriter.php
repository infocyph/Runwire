<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1;

use Closure;
use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\Network\WriteState;
use InvalidArgumentException;
use LogicException;

final class Http1ResponseWriter implements ResponseWriterInterface
{
    private bool $started = false;
    private bool $ended = false;
    private bool $chunked = false;
    private bool $bodySuppressed = false;
    private bool $closeAfter;
    private ?int $contentLength = null;
    private int $bodyBytes = 0;

    /** @var Closure(bool): void */
    private readonly Closure $onEnd;

    /** @param callable(bool): void $onEnd */
    public function __construct(
        private readonly Connection $connection,
        private readonly Http1Limits $limits,
        private readonly string $requestMethod,
        bool $keepAlive,
        callable $onEnd,
    ) {
        $this->closeAfter = !$keepAlive;
        /** @var Closure(bool): void $onEndClosure */
        $onEndClosure = Closure::fromCallable($onEnd);
        $this->onEnd = $onEndClosure;
    }

    public function isStarted(): bool { return $this->started; }
    public function isEnded(): bool { return $this->ended; }

    public function onDrain(callable $callback): self
    {
        $consumer = Closure::fromCallable($callback);
        $this->connection->onDrain(function () use ($consumer): void {
            if (!$this->ended) {
                $consumer($this);
            }
        });

        return $this;
    }

    public function start(int $status = 200, ?Headers $headers = null): WriteResult
    {
        if ($this->ended) {
            return $this->closedResult();
        }
        if ($this->started) {
            throw new LogicException('HTTP response has already started.');
        }
        if ($status < 200 || $status > 599) {
            throw new InvalidArgumentException('Final HTTP response status must be between 200 and 599.');
        }

        $headers ??= new Headers();
        [$fields, $contentLength, $closeRequested] = $this->normalizeHeaders($headers);
        $closeAfter = $this->closeAfter || $closeRequested;
        $bodySuppressed = $this->requestMethod === 'HEAD' || $status === 204 || $status === 304;

        if ($status === 204 && $contentLength !== null) {
            $fields = array_values(array_filter(
                $fields,
                static fn (HeaderField $field): bool => $field->name !== 'content-length',
            ));
            $contentLength = null;
        }

        $chunked = !$bodySuppressed && $contentLength === null;
        if ($chunked) {
            $fields[] = new HeaderField('transfer-encoding', 'chunked');
        }
        if ($closeAfter) {
            $fields = array_values(array_filter(
                $fields,
                static fn (HeaderField $field): bool => $field->name !== 'connection',
            ));
            $fields[] = new HeaderField('connection', 'close');
        }

        $result = $this->connection->write($this->serializeHead($status, $fields));
        if (!$result->accepted()) {
            return $result;
        }

        $this->started = true;
        $this->closeAfter = $closeAfter;
        $this->bodySuppressed = $bodySuppressed;
        $this->contentLength = $contentLength;
        $this->chunked = $chunked;

        return $result;
    }

    public function write(string $chunk): WriteResult
    {
        if ($this->ended) {
            return $this->closedResult();
        }
        if ($chunk === '') {
            return $this->writeEmpty();
        }
        if (strlen($chunk) > $this->limits->maxResponseChunkBytes) {
            return $this->limitResult();
        }

        $start = $this->startIfNeeded();
        if ($start !== null && !$start->accepted()) {
            return $start;
        }
        if ($this->bodySuppressed) {
            $this->bodyBytes += strlen($chunk);
            return $this->connection->write('');
        }

        $this->assertWithinContentLength($chunk);
        return $this->writeBodyChunk($chunk);
    }

    public function end(string $finalChunk = ''): WriteResult
    {
        if ($this->ended) {
            return $this->closedResult();
        }
        if (strlen($finalChunk) > $this->limits->maxResponseChunkBytes) {
            return $this->limitResult();
        }

        $start = $this->startForEndIfNeeded($finalChunk);
        if ($start !== null && !$start->accepted()) {
            return $start;
        }
        if ($this->bodySuppressed) {
            $this->bodyBytes += strlen($finalChunk);
            return $this->finish($this->connection->write(''));
        }
        if ($this->contentLength !== null) {
            return $this->endFixedLength($finalChunk);
        }

        return $this->endChunked($finalChunk);
    }

    private function writeEmpty(): WriteResult
    {
        return $this->started ? $this->connection->write('') : $this->start();
    }

    private function startIfNeeded(): ?WriteResult
    {
        return $this->started ? null : $this->start();
    }

    private function startForEndIfNeeded(string $finalChunk): ?WriteResult
    {
        if ($this->started) {
            return null;
        }

        return $this->start(200, new Headers([
            new HeaderField('content-length', (string) strlen($finalChunk)),
        ]));
    }

    private function assertWithinContentLength(string $chunk): void
    {
        if ($this->contentLength !== null && $this->bodyBytes + strlen($chunk) > $this->contentLength) {
            throw new LogicException('HTTP response body exceeds declared Content-Length.');
        }
    }

    private function writeBodyChunk(string $chunk): WriteResult
    {
        $wire = $this->chunked
            ? dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n"
            : $chunk;
        $result = $this->connection->write($wire);
        if ($result->accepted()) {
            $this->bodyBytes += strlen($chunk);
        }
        return $result;
    }

    private function endFixedLength(string $finalChunk): WriteResult
    {
        $next = $this->bodyBytes + strlen($finalChunk);
        if ($next !== $this->contentLength) {
            throw new LogicException(
                $next > $this->contentLength
                    ? 'HTTP response body exceeds declared Content-Length.'
                    : 'HTTP response body is shorter than declared Content-Length.',
            );
        }

        $result = $finalChunk === ''
            ? $this->connection->write('')
            : $this->connection->write($finalChunk);
        if ($result->accepted()) {
            $this->bodyBytes += strlen($finalChunk);
        }
        return $this->finish($result);
    }

    private function endChunked(string $finalChunk): WriteResult
    {
        $wire = $finalChunk === ''
            ? "0\r\n\r\n"
            : dechex(strlen($finalChunk)) . "\r\n" . $finalChunk . "\r\n0\r\n\r\n";
        $result = $this->connection->write($wire);
        if ($result->accepted()) {
            $this->bodyBytes += strlen($finalChunk);
        }
        return $this->finish($result);
    }

    /** @return array{0: list<HeaderField>, 1: ?int, 2: bool} */
    private function normalizeHeaders(Headers $headers): array
    {
        $fields = [];
        $contentLengths = [];
        $close = false;
        foreach ($headers->fields() as $field) {
            if ($field->name === 'transfer-encoding') {
                throw new InvalidArgumentException('Response Transfer-Encoding is owned by Runwire.');
            }
            if ($field->name === 'content-length') {
                $contentLengths[] = $this->parseContentLength(trim($field->value));
            }
            if ($field->name === 'connection' && $this->hasToken($field->value, 'close')) {
                $close = true;
            }
            $fields[] = $field;
        }

        if (count($fields) > $this->limits->maxResponseHeaderCount) {
            throw new InvalidArgumentException('Response header count exceeds configured limit.');
        }
        if ($contentLengths !== [] && count(array_unique($contentLengths)) !== 1) {
            throw new InvalidArgumentException('Conflicting response Content-Length fields.');
        }
        return [$fields, $contentLengths[0] ?? null, $close];
    }

    /** @param list<HeaderField> $fields */
    private function serializeHead(int $status, array $fields): string
    {
        $statusLine = 'HTTP/1.1 ' . $status . ' ' . self::reasonPhrase($status) . "\r\n";
        $parts = [$statusLine];
        $bytes = strlen($statusLine);

        foreach ($fields as $field) {
            $line = $field->name . ': ' . $field->value . "\r\n";
            $bytes += strlen($line);
            if ($bytes > $this->limits->maxResponseHeaderBytes) {
                throw new InvalidArgumentException('Response headers exceed configured byte limit.');
            }
            $parts[] = $line;
        }

        $bytes += 2;
        if ($bytes > $this->limits->maxResponseHeaderBytes) {
            throw new InvalidArgumentException('Response headers exceed configured byte limit.');
        }
        $parts[] = "\r\n";

        return implode('', $parts);
    }

    private function parseContentLength(string $value): int
    {
        if ($value === '' || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid response Content-Length.');
        }

        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $max = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($max)
            || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
            throw new InvalidArgumentException('Response Content-Length exceeds platform range.');
        }

        return (int) $normalized;
    }

    private function finish(WriteResult $result): WriteResult
    {
        if (!$result->accepted()) {
            return $result;
        }
        $this->ended = true;
        ($this->onEnd)($this->closeAfter);
        return $result;
    }

    private function closedResult(): WriteResult
    {
        return new WriteResult(WriteState::CLOSED, $this->connection->pendingWriteBytes());
    }

    private function limitResult(): WriteResult
    {
        return new WriteResult(WriteState::REJECTED_LIMIT, $this->connection->pendingWriteBytes());
    }

    private function hasToken(string $value, string $token): bool
    {
        foreach (explode(',', strtolower($value)) as $part) {
            if (trim($part) === $token) {
                return true;
            }
        }
        return false;
    }

    private static function reasonPhrase(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            206 => 'Partial Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            408 => 'Request Timeout',
            413 => 'Content Too Large',
            414 => 'URI Too Long',
            417 => 'Expectation Failed',
            426 => 'Upgrade Required',
            429 => 'Too Many Requests',
            431 => 'Request Header Fields Too Large',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'Status',
        };
    }
}
