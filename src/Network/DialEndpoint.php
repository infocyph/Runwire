<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use InvalidArgumentException;

/**
 * Validated outgoing TCP or Unix-domain endpoint.
 *
 * Parsing performs no DNS resolution or network I/O.
 */
/** @psalm-taint-specialize */
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

    /**
     * Parse an outbound endpoint without resolving hostname addresses.
     * @throws \InvalidArgumentException
     */
    public static function parse(string $uri): self
    {
        if ($uri === '' || str_contains($uri, "\0")) {
            throw new InvalidArgumentException('A nonempty endpoint URI without NUL bytes is required.');
        }

        return str_starts_with($uri, 'unix://') ? self::parseUnix($uri) : self::parseTcp($uri);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function parseTcp(string $uri): self
    {
        $parts = parse_url($uri);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'tcp'
            || !is_string($host) || !is_int($port)
            || $port < 1
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['path'])) {
            throw new InvalidArgumentException('TCP endpoints require tcp://host:port without URL credentials or suffixes.');
        }

        $isIpv6 = str_starts_with($host, '[') && str_ends_with($host, ']');
        if ($isIpv6) {
            $host = substr($host, 1, -1);
        }

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        self::validateHost($host, $isIpv6, $literal);

        return new self('tcp', $uri, $host, $port, null, $literal);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function parseUnix(string $uri): self
    {
        $path = substr($uri, 7);
        if (!str_starts_with($path, '/') || strlen($path) > 103
            || str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException('Unix endpoints require an absolute path of up to 103 bytes.');
        }

        return new self('unix', $uri, null, null, $path, false);
    }

    /**
     * @throws \InvalidArgumentException
     */
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
