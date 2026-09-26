<?php

declare(strict_types=1);

namespace Infocyph\Runwire\WebSocket;

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http1\Http1ResponseWriter;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;

/**
 * Validates and accepts native HTTP/1 RFC 6455 upgrade requests.
 */
final readonly class WebSocketUpgrade
{
    private const string ACCEPT_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * Accept an already-authorized WebSocket request, or write a bounded rejection response.
     *
     * Browser Origin headers are rejected unless an explicit origin policy is supplied.
     *
     * @param callable(string, HttpRequest): bool|null $originPolicy
     */
    public static function accept(
        HttpRequest $request,
        ResponseWriterInterface $writer,
        ?callable $originPolicy = null,
        ?string $subprotocol = null,
        ?WebSocketOptions $options = null,
    ): ?WebSocketSession {
        if (!$writer instanceof Http1ResponseWriter || $request->version !== ProtocolVersion::HTTP_1_1) {
            self::reject($writer, 426, ['sec-websocket-version' => '13']);

            return null;
        }
        if (strcasecmp($request->method, 'GET') !== 0) {
            self::reject($writer, 405);

            return null;
        }
        if (!self::hasToken($request->headers->all('connection'), 'upgrade')) {
            self::reject($writer, 400);

            return null;
        }
        if (!self::hasToken($request->headers->all('upgrade'), 'websocket')) {
            self::reject($writer, 400);

            return null;
        }
        if ($request->headers->all('sec-websocket-version') !== ['13']) {
            self::reject($writer, 426, ['sec-websocket-version' => '13']);

            return null;
        }

        $keys = $request->headers->all('sec-websocket-key');
        $key = count($keys) === 1 ? trim($keys[0]) : '';
        $decoded = $key === '' ? false : base64_decode($key, true);
        if (!is_string($decoded) || strlen($decoded) !== 16) {
            self::reject($writer, 400);

            return null;
        }
        if (
            $request->headers->has('transfer-encoding')
            || self::declaresNonZeroBody($request->headers)
            || !$request->body->eof()
        ) {
            self::reject($writer, 400);

            return null;
        }

        $origins = $request->headers->all('origin');
        if (count($origins) > 1) {
            self::reject($writer, 400);

            return null;
        }
        if ($origins !== []) {
            if ($originPolicy === null || !$originPolicy($origins[0], $request)) {
                self::reject($writer, 403);

                return null;
            }
        }

        if ($subprotocol !== null) {
            if (!self::validToken($subprotocol) || !in_array($subprotocol, self::subprotocols($request), true)) {
                self::reject($writer, 400);

                return null;
            }
        }

        $accept = base64_encode(sha1($key . self::ACCEPT_GUID, true));

        return $writer->upgradeWebSocket(
            $accept,
            $subprotocol,
            $options ?? new WebSocketOptions(),
        );
    }

    private static function declaresNonZeroBody(Headers $headers): bool
    {
        foreach ($headers->all('content-length') as $value) {
            if (trim($value) !== '0') {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $values */
    private static function hasToken(array $values, string $expected): bool
    {
        foreach ($values as $value) {
            foreach (explode(',', strtolower($value)) as $token) {
                if (trim($token) === $expected) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, string|list<string>> $headers */
    private static function reject(ResponseWriterInterface $writer, int $status, array $headers = []): void
    {
        if ($writer->isStarted() || $writer->isEnded()) {
            return;
        }

        $headers['content-length'] = '0';
        $start = $writer->start($status, Headers::fromArray($headers));
        if ($start->accepted()) {
            $writer->end();
        }
    }

    /** @return list<string> */
    private static function subprotocols(HttpRequest $request): array
    {
        $protocols = [];
        foreach ($request->headers->all('sec-websocket-protocol') as $value) {
            foreach (explode(',', $value) as $protocol) {
                $protocol = trim($protocol);
                if ($protocol !== '' && self::validToken($protocol)) {
                    $protocols[] = $protocol;
                }
            }
        }

        return $protocols;
    }

    private static function validToken(string $value): bool
    {
        return $value !== ''
            && preg_match("/^[!#$%&'*+.^_\x60|~0-9A-Za-z-]+$/D", $value) === 1;
    }
}
