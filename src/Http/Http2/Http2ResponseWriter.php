<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2;

use Closure;
use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\Network\WriteState;
use InvalidArgumentException;
use LogicException;

final class Http2ResponseWriter implements ResponseWriterInterface
{
    private bool $started = false;
    private bool $ended = false;
    private bool $bodySuppressed = false;
    private ?int $contentLength = null;
    private int $bodyBytes = 0;

    /** @var Closure(int, list<array{0: string, 1: string}>): WriteResult */
    private readonly Closure $sendHeaders;
    /** @var Closure(string, bool): WriteResult */
    private readonly Closure $sendData;
    /** @var Closure(Closure(): void): void */
    private readonly Closure $registerDrain;
    /** @var Closure(): void */
    private readonly Closure $onEnd;

    /**
     * @param callable(int, list<array{0: string, 1: string}>): WriteResult $sendHeaders
     * @param callable(string, bool): WriteResult $sendData
     * @param callable(Closure(): void): void $registerDrain
     * @param callable(): void $onEnd
     */
    public function __construct(
        private readonly string $requestMethod,
        callable $sendHeaders,
        callable $sendData,
        callable $registerDrain,
        callable $onEnd,
    ) {
        $this->sendHeaders = Closure::fromCallable($sendHeaders);
        $this->sendData = Closure::fromCallable($sendData);
        $this->registerDrain = Closure::fromCallable($registerDrain);
        $this->onEnd = Closure::fromCallable($onEnd);
    }

    public function isStarted(): bool { return $this->started; }
    public function isEnded(): bool { return $this->ended; }

    public function onDrain(callable $callback): self
    {
        $consumer = Closure::fromCallable($callback);
        ($this->registerDrain)(function () use ($consumer): void {
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

        [$fields, $contentLength] = $this->normalizeHeaders($headers ?? new Headers());
        $bodySuppressed = $this->requestMethod === 'HEAD' || $status === 204 || $status === 304;
        if ($status === 204 && $contentLength !== null) {
            $fields = array_values(array_filter($fields, static fn (HeaderField $field): bool => $field->name !== 'content-length'));
            $contentLength = null;
        }

        /** @var list<array{0: string, 1: string}> $block */
        $block = [[':status', (string) $status]];
        foreach ($fields as $field) {
            $block[] = [$field->name, $field->value];
        }
        $result = ($this->sendHeaders)($status, $block);
        if (!$result->accepted()) {
            return $result;
        }

        $this->started = true;
        $this->bodySuppressed = $bodySuppressed;
        $this->contentLength = $contentLength;
        return $result;
    }

    public function write(string $chunk): WriteResult
    {
        if ($this->ended) {
            return $this->closedResult();
        }
        if (!$this->started) {
            $start = $this->start();
            if (!$start->accepted()) {
                return $start;
            }
        }
        if ($chunk === '') {
            return ($this->sendData)('', false);
        }
        if ($this->contentLength !== null && $this->bodyBytes + strlen($chunk) > $this->contentLength) {
            throw new LogicException('HTTP response body exceeds declared Content-Length.');
        }
        if ($this->bodySuppressed) {
            $this->bodyBytes += strlen($chunk);
            return ($this->sendData)('', false);
        }

        $result = ($this->sendData)($chunk, false);
        if ($result->accepted()) {
            $this->bodyBytes += strlen($chunk);
        }
        return $result;
    }

    public function end(string $finalChunk = ''): WriteResult
    {
        if ($this->ended) {
            return $this->closedResult();
        }
        if (!$this->started) {
            $start = $this->start(200, new Headers([
                new HeaderField('content-length', (string) strlen($finalChunk)),
            ]));
            if (!$start->accepted()) {
                return $start;
            }
        }

        if ($this->contentLength !== null) {
            $next = $this->bodyBytes + strlen($finalChunk);
            if ($next !== $this->contentLength) {
                throw new LogicException(
                    $next > $this->contentLength
                        ? 'HTTP response body exceeds declared Content-Length.'
                        : 'HTTP response body is shorter than declared Content-Length.',
                );
            }
        }

        if ($this->bodySuppressed) {
            $this->bodyBytes += strlen($finalChunk);
            return $this->finish(($this->sendData)('', true));
        }

        $result = ($this->sendData)($finalChunk, true);
        if ($result->accepted()) {
            $this->bodyBytes += strlen($finalChunk);
        }
        return $this->finish($result);
    }

    /** @return array{0: list<HeaderField>, 1: ?int} */
    private function normalizeHeaders(Headers $headers): array
    {
        $fields = [];
        $contentLengths = [];
        foreach ($headers->fields() as $field) {
            if (in_array($field->name, ['connection', 'proxy-connection', 'keep-alive', 'transfer-encoding', 'upgrade'], true)) {
                throw new InvalidArgumentException(sprintf('Connection-specific response field "%s" is forbidden in HTTP/2.', $field->name));
            }
            if ($field->name === 'content-length') {
                $contentLengths[] = $this->parseLength($field->value);
            }
            $fields[] = $field;
        }
        if (count(array_unique($contentLengths, SORT_REGULAR)) > 1) {
            throw new InvalidArgumentException('Conflicting response Content-Length fields are forbidden.');
        }

        return [$fields, $contentLengths[0] ?? null];
    }

    private function parseLength(string $value): int
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid response Content-Length.');
        }
        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $max = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($max) || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
            throw new InvalidArgumentException('Response Content-Length exceeds platform integer range.');
        }
        return (int) $normalized;
    }

    private function finish(WriteResult $result): WriteResult
    {
        if (!$result->accepted()) {
            return $result;
        }
        $this->ended = true;
        ($this->onEnd)();
        return $result;
    }

    private function closedResult(): WriteResult
    {
        return new WriteResult(WriteState::CLOSED, 0);
    }
}
