<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Internal\ContentLengthParser;
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
    private string $buffer = '';

    private int $bodyBytes = 0;

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
            $beforeState = $this->state;
            $beforeBytes = strlen($this->buffer);
            switch ($this->state) {
                case 'head':
                    $this->readHead($onHead, $onEnd);
                    break;
                case 'fixed':
                    $this->readBody($onBody, $onEnd);
                    break;
                case 'chunk_line':
                    $this->readChunkLine();
                    break;
                case 'chunk_data':
                    $this->readChunkData($onBody);
                    break;
                case 'chunk_end':
                    if (strlen($this->buffer) >= 2) {
                        if (!str_starts_with($this->buffer, "\r\n")) {
                            throw new RuntimeException('Malformed HTTP/1.1 chunk terminator.');
                        }
                        $this->buffer = substr($this->buffer, 2);
                        $this->state = 'chunk_line';
                    }
                    break;
                case 'trailers':
                    $this->readTrailers($onEnd);
                    break;
                case 'until_eof':
                    if ($this->buffer !== '') {
                        $this->deliver($this->buffer, $onBody);
                        $this->buffer = '';
                    }
                    break;
                case 'done':
                    return;
                default:
                    throw new RuntimeException('Invalid HTTP/1.1 response parser state.');
            }
            if ($this->state === $beforeState && strlen($this->buffer) === $beforeBytes) {
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

    /** Parse one response header or trailer, rejecting obsolete folded lines. */
    private function parseField(string $line): HeaderField
    {
        if ($line === '' || str_starts_with($line, ' ') || str_starts_with($line, "\t")) {
            throw new RuntimeException('Malformed or folded HTTP/1.1 header.');
        }
        $colon = strpos($line, ':');
        if ($colon === false || $colon === 0) {
            throw new RuntimeException('Malformed HTTP/1.1 header field.');
        }

        return new HeaderField(substr($line, 0, $colon), trim(substr($line, $colon + 1), " \t"));
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

        $lines = explode("\r\n", substr($this->buffer, 0, $end));
        $statusLine = array_shift($lines);
        if (!is_string($statusLine)
            || preg_match('/^HTTP\/1\.([01]) ([1-5][0-9]{2})(?: [\x20-\x7E]*)?$/D', $statusLine, $match) !== 1) {
            throw new RuntimeException('Malformed HTTP/1.1 response status line.');
        }
        $fields = [];
        foreach ($lines as $line) {
            $fields[] = $this->parseField($line);
            if (count($fields) > 100) {
                throw new RuntimeException('HTTP/1.1 response header count exceeded.');
            }
        }
        $headers = new Headers($fields);
        $code = (int) $match[2];
        $this->buffer = substr($this->buffer, $end + 4);
        if ($code < 200) {
            if ($code === 101) {
                throw new RuntimeException('HTTP/1.1 protocol upgrade requires a dedicated owner.');
            }
            return; // Informational responses are consumed before final response.
        }
        $this->status = $code;
        $close = in_array('close', array_map('strtolower', $headers->all('connection')), true);
        $this->keepAlive = $match[1] === '1' && !$close;
        $transfer = $headers->all('transfer-encoding');
        $lengths = $headers->all('content-length');
        if (count($transfer) > 1 || count($lengths) > 1 || ($transfer !== [] && $lengths !== [])) {
            throw new RuntimeException('Ambiguous HTTP/1.1 response message framing.');
        }
        $chunked = $transfer !== [];
        if ($chunked && strtolower(trim($transfer[0])) !== 'chunked') {
            throw new RuntimeException('Unsupported HTTP/1.1 Transfer-Encoding.');
        }
        $length = $lengths === [] ? null : ContentLengthParser::parse($lengths[0]);
        if ($length !== null && $length > $this->maxBodyBytes) {
            throw new RuntimeException('HTTP/1.1 Content-Length exceeds body budget.');
        }
        $onHead($code, $headers);
        if ($this->headRequest || $code === 204 || $code === 304) {
            $this->complete($onEnd);
            return;
        }
        if ($chunked) {
            $this->state = 'chunk_line';
        } elseif ($length !== null) {
            $this->remaining = $length;
            $this->state = 'fixed';
            if ($length === 0) {
                $this->complete($onEnd);
            }
        } else {
            $this->keepAlive = false;
            $this->state = 'until_eof';
        }
    }

    private function readTrailers(callable $onEnd): void
    {
        $fields = [];
        $consumed = 0;
        while (true) {
            $end = strpos($this->buffer, "\r\n", $consumed);
            if ($end === false) {
                if (strlen($this->buffer) > $this->maxHeaderBytes) {
                    throw new RuntimeException('HTTP/1.1 response trailers exceed limit.');
                }
                return;
            }
            $line = substr($this->buffer, $consumed, $end - $consumed);
            $consumed = $end + 2;
            if ($consumed > $this->maxHeaderBytes) {
                throw new RuntimeException('HTTP/1.1 response trailers exceed limit.');
            }
            if ($line === '') {
                $this->buffer = substr($this->buffer, $consumed);
                $this->trailers = new Headers($fields);
                $this->complete($onEnd);
                return;
            }
            $field = $this->parseField($line);
            if (in_array($field->name, ['content-length', 'transfer-encoding', 'host', 'connection'], true)) {
                throw new RuntimeException('Framing/routing field forbidden in HTTP/1.1 trailers.');
            }
            $fields[] = $field;
            if (count($fields) > 100) {
                throw new RuntimeException('HTTP/1.1 response trailer count exceeded.');
            }
        }
    }
}
