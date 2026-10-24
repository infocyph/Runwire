<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Internal\ContentLengthParser;
use RuntimeException;

/**
 * Strict, stateless HTTP/1.1 response head and trailer framing validation.
 *
 * Owned independently of incremental body parsing to keep per-tick state
 * transitions bounded and avoid duplicate framing authorities.
 *
 * @internal
 */
final readonly class Http1ClientResponseHeadParser
{
    /**
     * @return array{code:int, headers:Headers, http11:bool, close:bool, chunked:bool, length:?int}
     */
    public static function parse(string $wireHead, int $maxBodyBytes): array
    {
        $lines = explode("\r\n", $wireHead);
        $statusLine = array_shift($lines) ?? '';
        if (preg_match('/^HTTP\/1\.([01]) ([1-5][0-9]{2})(?: [\x20-\x7E]*)?$/D', $statusLine, $match) !== 1) {
            throw new RuntimeException('Malformed HTTP/1.1 response status line.');
        }

        $headers = new Headers(self::fields($lines, false));
        [$chunked, $length] = self::framing($headers, $maxBodyBytes);

        return [
            'code' => (int) $match[2],
            'headers' => $headers,
            'http11' => $match[1] === '1',
            'close' => self::closes($headers),
            'chunked' => $chunked,
            'length' => $length,
        ];
    }

    /**
     * Parse a complete trailer section, or report that more wire bytes are needed.
     *
     * @return array{trailers:Headers, used:int}|null
     */
    public static function parseTrailers(string $buffer, int $maxHeaderBytes): ?array
    {
        if (strlen($buffer) > $maxHeaderBytes) {
            throw new RuntimeException('HTTP/1.1 response trailers exceed limit.');
        }
        if (str_starts_with($buffer, "\r\n")) {
            return ['trailers' => new Headers(), 'used' => 2];
        }
        $end = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            return null;
        }
        $fields = self::fields(explode("\r\n", substr($buffer, 0, $end)), true);

        return ['trailers' => new Headers($fields), 'used' => $end + 4];
    }

    private static function closes(Headers $headers): bool
    {
        foreach ($headers->all('connection') as $value) {
            foreach (explode(',', strtolower($value)) as $token) {
                if (trim($token) === 'close') {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<string> $lines
     * @return list<HeaderField>
     */
    private static function fields(array $lines, bool $trailers): array
    {
        if (count($lines) > 100) {
            throw new RuntimeException('HTTP/1.1 response header count exceeded.');
        }
        $fields = [];
        foreach ($lines as $line) {
            $field = self::parseField($line);
            if ($trailers && in_array($field->name, ['content-length', 'transfer-encoding', 'host', 'connection'], true)) {
                throw new RuntimeException('Framing/routing field forbidden in HTTP/1.1 trailers.');
            }
            $fields[] = $field;
        }

        return $fields;
    }

    /** @return array{bool, ?int} */
    private static function framing(Headers $headers, int $maxBodyBytes): array
    {
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
        if ($length !== null && $length > $maxBodyBytes) {
            throw new RuntimeException('HTTP/1.1 Content-Length exceeds body budget.');
        }

        return [$chunked, $length];
    }

    private static function parseField(string $line): HeaderField
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
}
