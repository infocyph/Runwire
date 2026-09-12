<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\ProtocolVersion;
use InvalidArgumentException;
use RuntimeException;

final class HostRequestFactory
{
    public function fromGlobals(int $maxBodyBytes): HttpRequest
    {
        return $this->fromServer($_SERVER, $this->readInput($maxBodyBytes), $maxBodyBytes);
    }

    /** @param array<string, mixed> $server */
    public function fromServer(array $server, string $body, int $maxBodyBytes): HttpRequest
    {
        if (strlen($body) > $maxBodyBytes) {
            throw new InvalidArgumentException('Host request body exceeds the configured limit.');
        }

        return new HttpRequest(
            method: $this->serverString($server, 'REQUEST_METHOD', 'GET'),
            target: $this->serverString($server, 'REQUEST_URI', '/'),
            version: $this->protocolVersion($this->serverString($server, 'SERVER_PROTOCOL', 'HTTP/1.1')),
            headers: Headers::fromArray($this->headers($server)),
            body: new BufferedRequestBody($body),
            peerAddress: $this->address($server, 'REMOTE_ADDR', 'REMOTE_PORT'),
            localAddress: $this->address($server, 'SERVER_ADDR', 'SERVER_PORT'),
            encrypted: $this->encrypted($server),
        );
    }

    /** @param array<string, mixed> $server */
    private function address(array $server, string $addressKey, string $portKey): ?string
    {
        $address = $server[$addressKey] ?? null;
        if (!is_string($address) || $address === '') {
            return null;
        }

        $port = $server[$portKey] ?? null;
        if (!is_int($port) && !is_string($port)) {
            return $address;
        }
        $portString = (string) $port;
        if ($portString === '' || preg_match('/^\d+$/D', $portString) !== 1) {
            return $address;
        }

        return str_contains($address, ':') ? sprintf('[%s]:%s', $address, $portString) : $address . ':' . $portString;
    }

    /** @param array<string, mixed> $server */
    private function encrypted(array $server): bool
    {
        $https = $server['HTTPS'] ?? null;
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return strtolower($this->serverString($server, 'REQUEST_SCHEME')) === 'https';
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private function headers(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || (!is_string($value) && !is_int($value) && !is_float($value))) {
                continue;
            }
            $headerName = $this->headerName($key);
            if ($headerName !== null) {
                $headers[$headerName] = (string) $value;
            }
        }

        return $headers;
    }

    private function headerName(string $serverKey): ?string
    {
        if (str_starts_with($serverKey, 'HTTP_')) {
            return str_replace('_', '-', strtolower(substr($serverKey, 5)));
        }

        return match ($serverKey) {
            'CONTENT_LENGTH' => 'content-length',
            'CONTENT_TYPE' => 'content-type',
            default => null,
        };
    }

    private function protocolVersion(string $protocol): ProtocolVersion
    {
        return match (strtoupper($protocol)) {
            'HTTP/2', 'HTTP/2.0' => ProtocolVersion::HTTP_2,
            'HTTP/3', 'HTTP/3.0' => ProtocolVersion::HTTP_3,
            default => ProtocolVersion::HTTP_1_1,
        };
    }

    private function readInput(int $maxBodyBytes): string
    {
        if ($maxBodyBytes < 1) {
            throw new InvalidArgumentException('Maximum host request body size must be positive.');
        }

        $stream = fopen('php://input', 'rb');
        if (!is_resource($stream)) {
            throw new RuntimeException('Unable to open the host request body stream.');
        }

        try {
            $body = '';
            while (!feof($stream)) {
                $chunk = fread($stream, min(8_192, $maxBodyBytes - strlen($body) + 1));
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read the host request body stream.');
                }
                $body .= $chunk;
                if (strlen($body) > $maxBodyBytes) {
                    throw new InvalidArgumentException('Host request body exceeds the configured limit.');
                }
                if ($chunk === '') {
                    break;
                }
            }

            return $body;
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string, mixed> $server */
    private function serverString(array $server, string $key, string $default = ''): string
    {
        $value = $server[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
