<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use InvalidArgumentException;

/**
 * Validated outgoing TCP or Unix-domain endpoint.
 *
 * Parsing performs no DNS resolution or network I/O.
 */
final readonly class DialEndpoint
{
    private function __construct(
        public string $scheme,
        public string $uri,
        public ?string $host,
        public ?int $port,
        public ?string $path,
        public bool $isIpLiteral,
    ) {}

    /** Parse an outbound endpoint without resolving hostname addresses. */
    public static function parse(string $uri): self
    {
        if ($uri === '' || str_contains($uri, "\0")) {
            throw new InvalidArgumentException('A nonempty endpoint URI without NUL bytes is required.');
        }

        return str_starts_with($uri, 'unix://') ? self::parseUnix($uri) : self::parseTcp($uri);
    }

    private static function parseTcp(string $uri): self
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'tcp'
            || !is_string($parts['host'] ?? null) || !is_int($parts['port'] ?? null)
            || $parts['port'] < 1
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['path'])) {
            throw new InvalidArgumentException('TCP endpoints require tcp://host:port without URL credentials or suffixes.');
        }

        $host = $parts['host'];
        $isIpv6 = str_starts_with($host, '[') && str_ends_with($host, ']');
        if ($isIpv6) {
            $host = substr($host, 1, -1);
        }

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        self::validateHost($host, $isIpv6, $literal);

        return new self('tcp', $uri, $host, $parts['port'], null, $literal);
    }

    private static function parseUnix(string $uri): self
    {
        $path = substr($uri, 7);
        if (!str_starts_with($path, '/') || strlen($path) > 103
            || str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException('Unix endpoints require an absolute path of up to 103 bytes.');
        }

        return new self('unix', $uri, null, null, $path, false);
    }

    private static function validateHost(string $host, bool $ipv6Brackets, bool $literal): void
    {
        if ($ipv6Brackets && (!$literal || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)) {
            throw new InvalidArgumentException('Brackets are only permitted around literal IPv6 addresses.');
        }
        if (!$literal && (!preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/iD', $host)
            || strlen($host) > 253 || str_contains($host, '..'))) {
            throw new InvalidArgumentException('Invalid TCP hostname.');
        }
    }
}
