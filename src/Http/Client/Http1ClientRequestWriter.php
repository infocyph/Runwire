<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\HeaderField;
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
    private readonly string $head;

    private int $bodyBytes = 0;

    private bool $done = false;

    private bool $headSent = false;

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

        $this->head = $this->buildHead($maxHeaderBytes);
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

    private function authority(string $host, ?int $port): string
    {
        $authority = str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
        if ($port !== null) {
            $authority .= ':' . $port;
        }

        return $authority;
    }

    private function buildHead(int $maxHeaderBytes): string
    {
        $parts = parse_url($this->request->uri);
        if (!is_array($parts) || !is_string($parts['host'] ?? null)) {

            throw new InvalidArgumentException('Invalid HTTP/1.1 request target.');
        }
        $authority = $this->authority($parts['host'], $parts['port'] ?? null);
        $target = $this->target($parts['path'] ?? '/', $parts['query'] ?? null);
        $lines = [
            $this->request->method . ' ' . $target . ' HTTP/1.1',
            'Host: ' . $authority,
            ...$this->requestHeaders($authority),
        ];
        $head = implode("\r\n", $lines) . "\r\n\r\n";
        if (strlen($head) > $maxHeaderBytes) {

            throw new InvalidArgumentException('HTTP/1.1 request headers exceed the configured budget.');
        }

        return $head;
    }

    /** Validate trailer names and sizes before the final chunk is sent. */
    private function finalChunk(?Headers $trailers): string
    {
        $frame = "0\r\n";
        foreach ($trailers?->fields() ?? [] as $field) {
            if (in_array($field->name, ['content-length', 'transfer-encoding', 'host', 'trailer', 'connection', 'proxy-authorization', 'proxy-connection'], true)) {

                throw new RuntimeException('HTTP/1.1 upload trailer contains a prohibited framing or routing field.');
            }
            $frame .= $field->name . ': ' . $field->value . "\r\n";
            if (strlen($frame) > 65_536) {

                throw new RuntimeException('HTTP/1.1 upload trailers exceed the limit.');
            }
        }

        return $frame . "\r\n";
    }

    /** @return list<string> */
    private function requestHeaders(string $authority): array
    {
        $fields = $this->request->headers->fields();
        if (count($fields) > 100) {

            throw new InvalidArgumentException('Too many HTTP/1.1 request headers.');
        }
        $lines = [];
        foreach ($fields as $field) {
            $line = $this->serializeField($field, $authority);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        if ($this->request->body !== null) {
            $lines[] = 'Transfer-Encoding: chunked';
        }

        return $lines;
    }

    private function serializeField(HeaderField $field, string $authority): ?string
    {
        if (in_array($field->name, ['proxy-authorization', 'proxy-connection'], true)) {
            throw new InvalidArgumentException('Proxy credentials and hop-by-hop proxy headers are forbidden on origin requests.');
        }
        if ($field->name === 'host') {
            if (strtolower(trim($field->value)) !== strtolower($authority)) {

                throw new InvalidArgumentException('HTTP Host header must match the verified request origin.');
            }

            return null;
        }
        if ($field->name === 'transfer-encoding'
            || ($this->request->body !== null && $field->name === 'content-length')) {

            throw new InvalidArgumentException('HTTP/1.1 request framing is owned by the serializer.');
        }
        if ($this->request->body === null && $field->name === 'content-length' && trim($field->value) !== '0') {

            throw new InvalidArgumentException('Nonzero Content-Length cannot be used without an upload body.');
        }

        return $field->name . ': ' . $field->value;
    }

    private function target(string $path, ?string $query): string
    {
        $target = $path === '' ? '/' : $path;
        if ($query !== null) {
            $target .= '?' . $query;
        }
        if (!str_starts_with($target, '/') || str_contains($target, "\r") || str_contains($target, "\n")) {

            throw new InvalidArgumentException('Invalid HTTP/1.1 origin-form request target.');
        }

        return $target;
    }
}
