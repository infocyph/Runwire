<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bounded HTTP/1.1 request serializer for an already verified H1 connection.
 *
 * The caller owns socket writes, partial-write draining and cancellation.
 * No alternate transport or implicit H2/H3 downgrade is performed here.
 */
final class Http1ClientRequestWriter
{
    private int $bodyBytes = 0;

    private bool $done = false;

    private bool $headSent = false;

    private readonly string $head;

    /** Build a request head, rejecting smuggling and ambiguous framing. */
    public function __construct(
        private readonly ClientRequest $request,
        private readonly int $maxBodyBytes = 16_777_216,
        int $maxHeaderBytes = 65_536,
    ) {
        if ($maxBodyBytes < 0 || $maxHeaderBytes < 1 || $maxHeaderBytes > 1_048_576) {
            throw new InvalidArgumentException('Invalid outbound HTTP/1.1 byte budget.');
        }
        if (!in_array(ProtocolVersion::HTTP_1_1, $request->protocol->allowedVersions, true)
            || ($request->protocol->preferred !== ProtocolVersion::HTTP_1_1 && !$request->protocol->allowFallback)) {
            throw new InvalidArgumentException('HTTP/1.1 request writer requires explicit protocol preference or authorized fallback.');
        }

        $url = parse_url($request->uri);
        if (!is_array($url) || !is_string($url['host'] ?? null)) {
            throw new InvalidArgumentException('Invalid HTTP/1.1 request target.');
        }
        $host = $url['host'];
        $authority = str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
        if (isset($url['port'])) {
            $authority .= ':' . $url['port'];
        }
        $target = ($url['path'] ?? '/') ?: '/';
        if (isset($url['query'])) {
            $target .= '?' . $url['query'];
        }
        if (!str_starts_with($target, '/') || str_contains($target, "\r") || str_contains($target, "\n")) {
            throw new InvalidArgumentException('Invalid HTTP/1.1 origin-form request target.');
        }

        $lines = [$request->method . ' ' . $target . ' HTTP/1.1', 'Host: ' . $authority];
        if (count($request->headers->fields()) > 100) {
            throw new InvalidArgumentException('Too many HTTP/1.1 request headers.');
        }
        foreach ($request->headers->fields() as $field) {
            if ($field->name === 'host') {
                if (strtolower(trim($field->value)) !== strtolower($authority)) {
                    throw new InvalidArgumentException('HTTP Host header must match the verified request origin.');
                }

                continue;
            }
            if ($field->name === 'transfer-encoding' || ($request->body !== null && $field->name === 'content-length')) {
                throw new InvalidArgumentException('HTTP/1.1 request framing is owned by the serializer.');
            }
            if ($request->body === null && $field->name === 'content-length' && trim($field->value) !== '0') {
                throw new InvalidArgumentException('Nonzero Content-Length cannot be used without an upload body.');
            }
            $lines[] = $field->name . ': ' . $field->value;
        }
        if ($request->body !== null) {
            $lines[] = 'Transfer-Encoding: chunked';
        }
        $this->head = implode("\r\n", $lines) . "\r\n\r\n";
        if (strlen($this->head) > $maxHeaderBytes) {
            throw new InvalidArgumentException('HTTP/1.1 request headers exceed the configured budget.');
        }
    }

    /** Whether all request bytes, including the final chunk, have been yielded. */
    public function done(): bool
    {
        return $this->done;
    }

    /**
     * Return one encoded frame; an empty return can also mean the producer
     * has no bytes available yet while done() remains false.
     */
    public function next(int $maxBodyChunkBytes = 65_536): string
    {
        if ($maxBodyChunkBytes < 1 || $maxBodyChunkBytes > 1_048_576) {
            throw new InvalidArgumentException('Invalid HTTP/1.1 write chunk budget.');
        }
        if ($this->done) {
            return '';
        }
        if (!$this->headSent) {
            $this->headSent = true;
            if ($this->request->body === null) {
                $this->done = true;
            }

            return $this->head;
        }

        $body = $this->request->body;
        if ($body === null) {
            $this->done = true;

            return '';
        }
        if ($body->eof()) {
            $this->done = true;

            return $this->finalChunk($body->trailers());
        }
        $bytes = $body->read(min($maxBodyChunkBytes, $this->maxBodyBytes - $this->bodyBytes + 1));
        if ($bytes === '') {
            return '';
        }
        $this->bodyBytes += strlen($bytes);
        if ($this->bodyBytes > $this->maxBodyBytes || strlen($bytes) > $maxBodyChunkBytes) {
            $this->done = true;
            throw new RuntimeException('HTTP/1.1 streaming upload exceeds the configured byte budget.');
        }

        return dechex(strlen($bytes)) . "\r\n" . $bytes . "\r\n";
    }

    /** Validate trailer names and sizes before the final chunk is sent. */
    private function finalChunk(?Headers $trailers): string
    {
        $frame = "0\r\n";
        foreach ($trailers?->fields() ?? [] as $field) {
            if (in_array($field->name, ['content-length', 'transfer-encoding', 'host', 'trailer', 'connection'], true)) {
                throw new RuntimeException('HTTP/1.1 upload trailer contains a prohibited framing or routing field.');
            }
            $frame .= $field->name . ': ' . $field->value . "\r\n";
            if (strlen($frame) > 65_536) {
                throw new RuntimeException('HTTP/1.1 upload trailers exceed the limit.');
            }
        }

        return $frame . "\r\n";
    }
}
