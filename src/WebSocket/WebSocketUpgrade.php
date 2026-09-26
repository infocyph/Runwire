<?php

declare(strict_types=1);

namespace Infocyph\Runwire\WebSocket;

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http1\Http1ResponseWriter;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\ContentLengthParser;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use InvalidArgumentException;
use OverflowException;

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
        $rejection = self::technicalRejection($request, $writer);
        if ($rejection !== null) {
            self::reject($writer, $rejection[0], $rejection[1]);

            return null;
        }
        if (!self::originAllowed($request, $originPolicy)) {
            self::reject($writer, 403);

            return null;
        }

        $offered = self::subprotocols($request);
        if ($offered === null || ($subprotocol !== null && !self::selectedProtocolAllowed($subprotocol, $offered))) {
            self::reject($writer, 400);

            return null;
        }

        $key = trim($request->headers->all('sec-websocket-key')[0]);
        $accept = base64_encode(sha1($key . self::ACCEPT_GUID, true));

        return $writer->upgradeWebSocket(
            $accept,
            $subprotocol,
            $options ?? new WebSocketOptions(),
        );
    }

    private static function bodyIsEmpty(HttpRequest $request): bool
    {
        if ($request->headers->has('transfer-encoding') || !$request->body->eof()) {
            return false;
        }

        foreach ($request->headers->all('content-length') as $value) {
            try {
                if (ContentLengthParser::parse($value) !== 0) {
                    return false;
                }
            } catch (InvalidArgumentException|OverflowException) {
                return false;
            }
        }

        return true;
    }

    private static function hasValidKey(HttpRequest $request): bool
    {
        $keys = $request->headers->all('sec-websocket-key');
        if (count($keys) !== 1) {
            return false;
        }

        $decoded = base64_decode(trim($keys[0]), true);

        return is_string($decoded) && strlen($decoded) === 16;
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

    /** @param callable(string, HttpRequest): bool|null $originPolicy */
    private static function originAllowed(HttpRequest $request, ?callable $originPolicy): bool
    {
        $origins = $request->headers->all('origin');
        if (count($origins) > 1) {
            return false;
        }
        if ($origins === []) {
            return true;
        }

        return $originPolicy !== null && $originPolicy($origins[0], $request);
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

    /** @param list<string> $offered */
    private static function selectedProtocolAllowed(string $subprotocol, array $offered): bool
    {
        return self::validToken($subprotocol) && in_array($subprotocol, $offered, true);
    }

    /** @return ?list<string> */
    private static function subprotocols(HttpRequest $request): ?array
    {
        $protocols = [];
        foreach ($request->headers->all('sec-websocket-protocol') as $value) {
            foreach (explode(',', $value) as $protocol) {
                $protocol = trim($protocol);
                if (!self::validToken($protocol)) {
                    return null;
                }

                $protocols[] = $protocol;
            }
        }

        return array_values(array_unique($protocols));
    }

    /** @return null|array{0: int, 1: array<string, string|list<string>>} */
    private static function technicalRejection(
        HttpRequest $request,
        ResponseWriterInterface $writer,
    ): ?array {
        if (!$writer instanceof Http1ResponseWriter || $request->version !== ProtocolVersion::HTTP_1_1) {
            return [426, ['sec-websocket-version' => '13']];
        }
        if (strcasecmp($request->method, 'GET') !== 0) {
            return [405, []];
        }
        if (
            !self::hasToken($request->headers->all('connection'), 'upgrade')
            || !self::hasToken($request->headers->all('upgrade'), 'websocket')
        ) {
            return [400, []];
        }
        if ($request->headers->all('sec-websocket-version') !== ['13']) {
            return [426, ['sec-websocket-version' => '13']];
        }
        if (!self::hasValidKey($request) || !self::bodyIsEmpty($request)) {
            return [400, []];
        }

        return null;
    }

    private static function validToken(string $value): bool
    {
        return $value !== ''
            && preg_match("/^[!#$%&'*+.^_\x60|~0-9A-Za-z-]+$/D", $value) === 1;
    }
}
