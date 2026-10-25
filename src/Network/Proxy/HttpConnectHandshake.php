<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Proxy;

use InvalidArgumentException;
use RuntimeException;

/** Strict bounded HTTP/1.1 CONNECT exchange without socket or TLS ownership. */
final readonly class HttpConnectHandshake
{
    public const int MAX_HEADER_BYTES = 8192;

    public function __construct(
        private ?string $username = null,
        private ?string $password = null,
        private bool $verifiedProxyTransport = false,
    ) {
        if (($username === null) !== ($password === null)) {
            throw new InvalidArgumentException('CONNECT authentication credentials must be paired.');
        }
        if ($username !== null) {
            if (!$verifiedProxyTransport) {
                throw new InvalidArgumentException('CONNECT credentials require verified encrypted proxy transport.');
            }
            if ($username === '' || strpbrk($username, ":\r\n\0") !== false
                || strlen($username) > 255 || strlen($password) > 255) {
                throw new InvalidArgumentException('Invalid CONNECT proxy credentials.');
            }
        }
    }

    /** Whether the request carries proxy credentials requiring verified transport. */
    public function usesAuthentication(): bool
    {
        return $this->username !== null;
    }

    /** Build CONNECT for the destination origin, never an arbitrary request path. */
    public function request(string $host, int $port): string
    {
        if ($port < 1 || $port > 65_535) {
            throw new InvalidArgumentException('CONNECT port is out of range.');
        }
        if (str_starts_with($host, '[') || str_ends_with($host, ']')) {
            if (!str_starts_with($host, '[') || !str_ends_with($host, ']')
                || filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException('Invalid bracketed CONNECT IPv6 endpoint.');
            }
            $host = '[' . substr($host, 1, -1) . ']';
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $host . ']';
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            self::validateHost($host);
        }
        $authority = $host . ':' . $port;
        $auth = $this->username === null ? '' : 'Proxy-Authorization: Basic '
            . base64_encode($this->username . ':' . $this->password) . "\r\n";

        return 'CONNECT ' . $authority . " HTTP/1.1\r\n"
            . 'Host: ' . $authority . "\r\n" . $auth . "\r\n";
    }

    /** Parse fragmented 2xx tunnel response, returning consumed length without stealing TLS bytes. */
    public function responseLength(string $buffer): ?int
    {
        $end = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            if (strlen($buffer) > self::MAX_HEADER_BYTES) {
                throw new RuntimeException('CONNECT response exceeds the bounded header budget.');
            }

            return null;
        }
        if ($end + 4 > self::MAX_HEADER_BYTES) {
            throw new RuntimeException('CONNECT response exceeds the bounded header budget.');
        }
        $lines = explode("\r\n", substr($buffer, 0, $end));
        $status = array_shift($lines);
        if (!is_string($status) || preg_match('/^HTTP\/1\.[01] 200(?: [\x20-\x7e]*)?$/D', $status) !== 1) {
            throw new RuntimeException('CONNECT proxy did not establish a successful tunnel.');
        }
        if (count($lines) > 64) {
            throw new RuntimeException('CONNECT response contains too many headers.');
        }
        foreach ($lines as $line) {
            if (strlen($line) > 2048 || preg_match('/^[!#$%&\x27*+.^_\x60|~0-9A-Za-z-]+:[\x20-\x7e\t]*$/D', $line) !== 1) {
                throw new RuntimeException('CONNECT response contains malformed header fields.');
            }
            [$name] = explode(':', $line, 2);
            if (in_array(strtolower($name), ['transfer-encoding', 'content-length'], true)) {
                throw new RuntimeException('CONNECT response must not frame a body before tunneled TLS.');
            }
            if (strtolower($name) === 'connection' && preg_match('/(?:^|[, \t])close(?:$|[, \t])/i', substr($line, strlen($name) + 1)) === 1) {
                throw new RuntimeException('CONNECT proxy cannot close the established tunnel.');
            }
        }

        return $end + 4;
    }

    private static function validateHost(string $host): void
    {
        if ($host === '' || strlen($host) > 253 || str_contains($host, ':')) {
            throw new InvalidArgumentException('Invalid CONNECT hostname.');
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63
                || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/D', $label) !== 1) {
                throw new InvalidArgumentException('Invalid CONNECT hostname label.');
            }
        }
    }
}
