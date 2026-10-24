<?php

declare(strict_types=1);

namespace Infocyph\Runwire\WebSocket\Internal;

use InvalidArgumentException;
use RuntimeException;

/** Pure, bounded RFC 6455 client nonce and upgrade validation. @internal */
final readonly class WebSocketClientHandshake
{
    private const string ACCEPT_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * Build an origin-form upgrade request; never opens a socket or downgrades TLS.
     *
     * @param list<string> $protocols
     * @return array{wire:string,key:string,secure:bool,host:string,port:int}
     */
    public static function build(string $uri, array $protocols = []): array
    {
        $endpoint = self::parseEndpoint($uri);
        self::validateProtocols($protocols);

        $key = base64_encode(random_bytes(16));
        $authority = $endpoint['host'] . ($endpoint['explicitPort'] ? ':' . $endpoint['port'] : '');
        $head = [
            'GET ' . $endpoint['target'] . ' HTTP/1.1',
            'Host: ' . $authority,
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . $key,
            'Sec-WebSocket-Version: 13',
        ];
        if ($protocols !== []) {
            $head[] = 'Sec-WebSocket-Protocol: ' . implode(', ', $protocols);
        }
        $wire = implode("\r\n", $head) . "\r\n\r\n";
        if (strlen($wire) > 8_192) {
            throw new InvalidArgumentException('WebSocket upgrade header budget exceeded.');
        }

        return ['wire' => $wire, 'key' => $key, 'secure' => $endpoint['secure'], 'host' => $endpoint['host'], 'port' => $endpoint['port']];
    }

    /**
     * Verify a full 101 response head from an already authenticated connection.
     *
     * @param list<string> $offered
     */
    public static function verify(string $head, string $key, array $offered = []): ?string
    {
        if (strlen($head) > 8_192 || preg_match('/^[A-Za-z0-9+\/]{22}==$/D', $key) !== 1
            || strlen((string) base64_decode($key, true)) !== 16) {
            throw new InvalidArgumentException('WebSocket response budget or nonce is invalid.');
        }
        self::validateProtocols($offered);
        $lines = explode("\r\n", $head);
        $status = array_shift($lines);
        if (preg_match('/^HTTP\/1\.1 101(?: [\x20-\x7E]*)?$/D', (string) $status) !== 1) {
            throw new RuntimeException('WebSocket peer did not accept a valid HTTP/1.1 upgrade.');
        }
        $headers = self::headers($lines);
        if (strtolower($headers['upgrade'] ?? '') !== 'websocket'
            || !in_array('upgrade', array_map(trim(...), explode(',', strtolower($headers['connection'] ?? ''))), true)) {
            throw new RuntimeException('WebSocket Upgrade and Connection tokens are invalid.');
        }
        $expected = base64_encode(sha1($key . self::ACCEPT_GUID, true));
        if (!hash_equals($expected, $headers['sec-websocket-accept'] ?? '')) {
            throw new RuntimeException('WebSocket Sec-WebSocket-Accept mismatches the client nonce.');
        }
        if (isset($headers['sec-websocket-extensions'])) {
            throw new RuntimeException('Unsupported WebSocket extension negotiated.');
        }
        $selected = $headers['sec-websocket-protocol'] ?? null;
        if ($selected !== null && !in_array($selected, $offered, true)) {
            throw new RuntimeException('WebSocket peer selected an unoffered subprotocol.');
        }

        return $selected;
    }

    /**
     * @param list<string> $lines
     * @return array<string,string>
     */
    private static function headers(array $lines): array
    {
        if (count($lines) > 100) {
            throw new RuntimeException('Too many WebSocket response headers.');
        }
        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0
                || preg_match("/^[!#$%&'*+.^_\x60|~0-9A-Za-z-]+$/D", substr($line, 0, $colon)) !== 1
                || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', substr($line, $colon + 1)) === 1) {
                throw new RuntimeException('Malformed or folded WebSocket response header.');
            }
            $name = strtolower(substr($line, 0, $colon));
            if (isset($headers[$name])) {
                throw new RuntimeException('Duplicate WebSocket upgrade header.');
            }
            $headers[$name] = trim(substr($line, $colon + 1), " \t");
        }

        return $headers;
    }

    /**
     * @return array{host:string,port:int,secure:bool,explicitPort:bool,target:string}
     */
    private static function parseEndpoint(string $uri): array
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $uri) === 1) {
            throw new InvalidArgumentException('WebSocket URL contains forbidden control bytes.');
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['ws', 'wss'], true)
            || !is_string($parts['host'] ?? null) || isset($parts['user'])
            || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('WebSocket URL must be a credential-free ws/wss origin.');
        }
        $host = $parts['host'];
        if (!preg_match('/^[A-Za-z0-9.-]+$/D', $host)
            && !preg_match('/^\[[0-9a-fA-F:.]+\]$/D', $host)) {
            throw new InvalidArgumentException('WebSocket authority is invalid.');
        }
        $secure = $parts['scheme'] === 'wss';

        return [
            'host' => $host,
            'port' => $parts['port'] ?? ($secure ? 443 : 80),
            'secure' => $secure,
            'explicitPort' => isset($parts['port']) && $parts['port'] !== ($secure ? 443 : 80),
            'target' => self::requestTarget($parts),
        ];
    }

    /**
     * @param array{path?:string,query?:string} $parts
     */
    private static function requestTarget(array $parts): string
    {
        $target = ($parts['path'] ?? '/') ?: '/';
        if (isset($parts['query'])) {
            $target .= '?' . $parts['query'];
        }
        if (!str_starts_with($target, '/') || preg_match('/[\x00-\x20\x7F]/', $target) === 1) {
            throw new InvalidArgumentException('WebSocket origin-form path is unsafe.');
        }

        return $target;
    }

    /** @param list<string> $protocols */
    private static function validateProtocols(array $protocols): void
    {
        if (count($protocols) > 16 || count(array_unique($protocols)) !== count($protocols)) {
            throw new InvalidArgumentException('WebSocket offered subprotocols must be bounded and unique.');
        }
        foreach ($protocols as $protocol) {
            if (strlen($protocol) > 128
                || preg_match("/^[!#$%&'*+.^_\x60|~0-9A-Za-z-]+$/D", $protocol) !== 1) {
                throw new InvalidArgumentException('WebSocket subprotocol token is invalid.');
            }
        }
    }
}
