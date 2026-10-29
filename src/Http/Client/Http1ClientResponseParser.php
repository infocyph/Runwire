<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\Http\Headers;
use InvalidArgumentException;
use RuntimeException;

/**
 * Streaming HTTP/1.1 response decoder with bounded metadata and body budgets.
 *
 * It delivers decoded body slices directly to callers, without aggregating the
 * response. The caller must stop reusing sockets after an EOF-framed body.
 */
final class Http1ClientResponseParser
{
    private int $bodyBytes = 0;

    private string $buffer = '';

    private bool $done = false;

    private bool $keepAlive = false;

    private int $remaining = 0;

    private string $state = 'head';

    private ?int $status = null;

    private ?Headers $trailers = null;

    /** Construct strict limits; HEAD responses never carry an HTTP body. */
    public function __construct(
        private readonly bool $headRequest = false,
        private readonly int $maxBodyBytes = 16_777_216,
        private readonly int $maxHeaderBytes = 65_536,
    ) {
        if ($maxBodyBytes < 0 || $maxHeaderBytes < 128 || $maxHeaderBytes > 1_048_576) {

            throw new InvalidArgumentException('Invalid HTTP/1.1 response decoder limits.');
        }
    }

    /** Whether a complete response has been received. */
    public function done(): bool
    {
        return $this->done;
    }

    /** End a connection-close-delimited response, or reject a premature EOF. */
    public function finish(callable $onEnd): void
    {
        if ($this->done) {

            return;
        }
        if ($this->state !== 'until_eof' || $this->buffer !== '') {

            throw new RuntimeException('HTTP/1.1 peer closed before a complete framed response.');
        }
        $this->complete($onEnd);
    }

    /** Whether this completed response is eligible for HTTP/1.1 reuse. */
    public function keepAlive(): bool
    {
        return $this->done && $this->keepAlive;
    }

    /** Consume network data, delivering head/body/end incrementally. */
    public function push(string $bytes, callable $onHead, callable $onBody, callable $onEnd): void
    {
        if ($this->done || strlen($bytes) > 65_536 || strlen($this->buffer) + strlen($bytes) > $this->maxHeaderBytes + 65_536) {

            throw new RuntimeException('HTTP/1.1 response frame exceeds parser state or is already finished.');
        }
        $this->buffer .= $bytes;

        for ($step = 0; $step < 1_024; ++$step) {
            $previousState = $this->state;
            $previousBytes = strlen($this->buffer);
            $this->advance($onHead, $onBody, $onEnd);
            if ($this->state === 'done'
                || ($this->state === $previousState && strlen($this->buffer) === $previousBytes)) {

                return;
            }
        }

        throw new RuntimeException('HTTP/1.1 response parser exceeded its per-call step budget.');
    }

    /** Final HTTP status code, if a complete noninformational head exists. */
    public function status(): ?int
    {
        return $this->status;
    }

    /** Parsed trailer fields after the final chunk. */
    public function trailers(): ?Headers
    {
        return $this->trailers;
    }

    private function advance(callable $onHead, callable $onBody, callable $onEnd): void
    {
        switch ($this->state) {
            case 'head':
                $this->readHead($onHead, $onEnd);

                return;
            case 'fixed':
                $this->readBody($onBody, $onEnd);

                return;
            case 'chunk_line':
                $this->readChunkLine();

                return;
            case 'chunk_data':
                $this->readChunkData($onBody);

                return;
            case 'chunk_end':
                $this->readChunkEnd();

                return;
            case 'trailers':
                $this->readTrailers($onEnd);

                return;
            case 'until_eof':
                $this->readUntilEof($onBody);

                return;
            case 'done':
                return;
            default:
                throw new RuntimeException('Invalid HTTP/1.1 response parser state.');
        }
    }

    private function complete(callable $onEnd): void
    {
        if ($this->buffer !== '') {

            throw new RuntimeException('Unexpected HTTP/1.1 bytes after a complete response.');
        }
        $this->done = true;
        $this->state = 'done';
        $onEnd($this->trailers);
    }

    private function deliver(string $bytes, callable $onBody): void
    {
        if ($bytes === '') {

            return;
        }
        if (strlen($bytes) > $this->maxBodyBytes - $this->bodyBytes) {

            throw new RuntimeException('HTTP/1.1 response body exceeds configured size.');
        }
        $this->bodyBytes += strlen($bytes);
        $onBody($bytes);
    }

    private function prepareBody(bool $chunked, ?int $length, callable $onEnd): void
    {
        if ($chunked) {
            $this->state = 'chunk_line';

            return;
        }
        if ($length === null) {
            $this->keepAlive = false;
            $this->state = 'until_eof';

            return;
        }
        $this->remaining = $length;
        $this->state = 'fixed';
        if ($length === 0) {
            $this->complete($onEnd);
        }
    }

    private function readBody(callable $onBody, callable $onEnd): void
    {
        $available = min(strlen($this->buffer), $this->remaining);
        if ($available > 0) {
            $chunk = substr($this->buffer, 0, $available);
            $this->buffer = substr($this->buffer, $available);
            $this->remaining -= $available;
            $this->deliver($chunk, $onBody);
        }
        if ($this->remaining === 0) {
            $this->complete($onEnd);
        }
    }

    private function readChunkData(callable $onBody): void
    {
        $take = min(strlen($this->buffer), $this->remaining);
        if ($take === 0) {

            return;
        }
        $chunk = substr($this->buffer, 0, $take);
        $this->buffer = substr($this->buffer, $take);
        $this->remaining -= $take;
        $this->deliver($chunk, $onBody);
        if ($this->remaining === 0) {
            $this->state = 'chunk_end';
        }
    }

    private function readChunkEnd(): void
    {
        if (strlen($this->buffer) < 2) {

            return;
        }
        if (!str_starts_with($this->buffer, "\r\n")) {

            throw new RuntimeException('Malformed HTTP/1.1 chunk terminator.');
        }
        $this->buffer = substr($this->buffer, 2);
        $this->state = 'chunk_line';
    }

    private function readChunkLine(): void
    {
        $end = strpos($this->buffer, "\r\n");
        if ($end === false) {
            if (strlen($this->buffer) > 128) {

                throw new RuntimeException('HTTP/1.1 chunk size line exceeds limit.');
            }

            return;
        }
        if ($end > 128) {

            throw new RuntimeException('HTTP/1.1 chunk size line exceeds limit.');
        }
        $line = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 2);
        $sizeText = explode(';', $line, 2)[0];
        if (str_contains($line, ';') && preg_match('/[\x00-\x1F\x7F]/', substr($line, strpos($line, ';') + 1)) === 1) {

            throw new RuntimeException('Malformed HTTP/1.1 chunk extension.');
        }
        if ($sizeText === '' || preg_match('/^[0-9a-fA-F]+$/D', $sizeText) !== 1) {

            throw new RuntimeException('Malformed HTTP/1.1 chunk size.');
        }
        $value = 0;
        foreach (str_split($sizeText) as $digit) {
            $hex = (int) hexdec($digit);
            if ($value > intdiv($this->maxBodyBytes - $this->bodyBytes - $hex, 16)) {

                throw new RuntimeException('HTTP/1.1 chunk exceeds response byte budget.');
            }
            $value = $value * 16 + $hex;
        }
        $this->remaining = $value;
        $this->state = $value === 0 ? 'trailers' : 'chunk_data';
    }

    private function readHead(callable $onHead, callable $onEnd): void
    {
        $end = strpos($this->buffer, "\r\n\r\n");
        if ($end === false) {
            if (strlen($this->buffer) > $this->maxHeaderBytes) {

                throw new RuntimeException('HTTP/1.1 response head exceeds byte budget.');
            }

            return;
        }
        if ($end + 4 > $this->maxHeaderBytes) {

            throw new RuntimeException('HTTP/1.1 response headers exceed byte budget.');
        }

        $head = Http1ClientResponseHeadParser::parse(substr($this->buffer, 0, $end), $this->maxBodyBytes);
        $this->buffer = substr($this->buffer, $end + 4);
        if ($head['code'] < 200) {
            if ($head['code'] === 101) {

                throw new RuntimeException('HTTP/1.1 protocol upgrade requires a dedicated owner.');
            }

            return;
        }
        $this->status = $head['code'];
        $this->keepAlive = $head['http11'] && !$head['close'];
        $onHead($head['code'], $head['headers']);
        if ($this->headRequest || in_array($head['code'], [204, 304], true)) {
            $this->complete($onEnd);

            return;
        }

        $this->prepareBody($head['chunked'], $head['length'], $onEnd);
    }

    private function readTrailers(callable $onEnd): void
    {
        $parsed = Http1ClientResponseHeadParser::parseTrailers($this->buffer, $this->maxHeaderBytes);
        if ($parsed === null) {

            return;
        }
        $this->buffer = substr($this->buffer, $parsed['used']);
        $this->trailers = $parsed['trailers'];
        $this->complete($onEnd);
    }

    private function readUntilEof(callable $onBody): void
    {
        if ($this->buffer === '') {

            return;
        }
        $this->deliver($this->buffer, $onBody);
        $this->buffer = '';
    }
}
