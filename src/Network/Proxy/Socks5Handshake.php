<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Proxy;

use InvalidArgumentException;
use RuntimeException;

/** Bounded RFC 1928/1929 SOCKS5 wire codec, without network or TLS ownership. */
final readonly class Socks5Handshake
{
    /** Validate bounded SOCKS5 credentials and remote DNS disclosure policy. */
    public function __construct(
        private bool $remoteDns = false,
        private ?string $username = null,
        private ?string $password = null,
        bool $verifiedProxyTransport = false,
    ) {
        if (($username === null) !== ($password === null)) {
            throw new InvalidArgumentException('SOCKS5 credentials must be paired.');
        }
        if ($username !== null && $password !== null) {
            if (!$verifiedProxyTransport) {
                throw new InvalidArgumentException('SOCKS5 credentials require verified encrypted proxy transport.');
            }
            if ($username === '' || $password === '' || strlen($username) > 255 || strlen($password) > 255) {
                throw new InvalidArgumentException('SOCKS5 credentials must contain 1–255 octets.');
            }
        }
    }

    /** Enforce numeric target unless remote DNS was explicitly allowed. */
    public function connectRequest(string $host, int $port): string
    {
        if ($port < 1 || $port > 65_535) {
            throw new InvalidArgumentException('Invalid SOCKS5 destination port.');
        }
        $target = str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;
        if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $address = "\x01" . inet_pton($target);
        } elseif (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $address = "\x04" . inet_pton($target);
        } else {
            if (!$this->remoteDns) {
                throw new InvalidArgumentException('SOCKS5 local DNS policy requires a pre-resolved IP.');
            }
            self::validateDomain($target);
            $address = "\x03" . chr(min(255, strlen($target))) . $target;
        }

        return "\x05\x01\x00" . $address . pack('n', $port);
    }

    /** Build one bounded RFC 1929 authentication request. */
    public function credentials(): string
    {
        if ($this->username === null || $this->password === null) {
            throw new InvalidArgumentException('SOCKS5 authentication is not configured.');
        }

        return "\x01" . chr(min(255, strlen($this->username))) . $this->username
            . chr(min(255, strlen($this->password))) . $this->password;
    }

    /** Return the exactly-one-method SOCKS5 negotiation greeting. */
    public function greeting(): string
    {
        return $this->username === null ? "\x05\x01\x00" : "\x05\x01\x02";
    }

    /** Complete reply frame length or null for fragmented frames; leave tunnel bytes untouched. */
    public function replyLength(string $buffer): ?int
    {
        if (strlen($buffer) < 4) {
            return null;
        }
        if ($buffer[0] !== "\x05" || $buffer[1] !== "\x00" || $buffer[2] !== "\x00") {
            throw new RuntimeException('SOCKS5 CONNECT failed or returned invalid metadata.');
        }
        $length = match ($buffer[3]) {
            "\x01" => 10,
            "\x04" => 22,
            "\x03" => isset($buffer[4]) ? 7 + ord($buffer[4]) : null,
            default => throw new RuntimeException('Invalid SOCKS5 reply address type.'),
        };
        if ($length === null) {
            return null;
        }
        if ($buffer[3] === "\x03" && $length === 7) {
            throw new RuntimeException('Empty SOCKS5 reply domain.');
        }

        return strlen($buffer) >= $length ? $length : null;
    }

    /** Whether SOCKS5 requires proxy credentials and an independently verified TLS owner. */
    public function usesAuthentication(): bool
    {
        return $this->username !== null;
    }

    /** Verify the required proxy authentication response. */
    public function verifyAuthentication(string $reply): void
    {
        if ($this->username === null || $reply !== "\x01\x00") {
            throw new RuntimeException('SOCKS5 proxy authentication failed.');
        }
    }

    /** Reject any SOCKS5 method substitution or unauthenticated downgrade. */
    public function verifyMethod(string $reply): void
    {
        $expected = $this->username === null ? "\x00" : "\x02";
        if (strlen($reply) !== 2 || $reply[0] !== "\x05" || $reply[1] !== $expected) {
            throw new RuntimeException('SOCKS5 proxy rejected the required authentication method.');
        }
    }

    /** Execute the bounded proxy exchange phase. */
    private static function validateDomain(string $host): void
    {
        if ($host === '' || strlen($host) > 253 || str_contains($host, ':')) {
            throw new InvalidArgumentException('Invalid SOCKS5 target hostname.');
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63
                || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/D', $label) !== 1) {
                throw new InvalidArgumentException('Invalid SOCKS5 target hostname label.');
            }
        }
    }
}
