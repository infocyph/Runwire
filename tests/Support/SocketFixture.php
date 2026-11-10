<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Support;

use RuntimeException;

/** Build a fixture failure from the nullable native socket error output. */
function socketFailure(?string $message, string $fallback): RuntimeException
{
    return new RuntimeException($message ?? $fallback);
}

/**
 * Release a borrowed handle that its connection owner may already have closed.
 *
 * @param resource|closed-resource|null $stream
 */
function closeSocket(mixed $stream): void
{
    if (is_resource($stream)) {
        fclose($stream);
    }
}

/**
 * @param array<string,resource> $sockets
 * @return resource
 * @throws RuntimeException
 */
function socketAt(array $sockets, string $name): mixed
{
    return $sockets[$name] ?? throw new RuntimeException('Fixture socket was not registered: ' . $name);
}

/** @param resource $socket */
function sendDatagramReply(mixed $socket, string $payload, mixed $peer): void
{
    if (is_string($peer)) {
        stream_socket_sendto($socket, $payload, 0, $peer);
    }
}

/**
 * @return resource
 * @throws RuntimeException
 */
function socketHandle(mixed $value): mixed
{
    if (!is_resource($value)) {
        throw new RuntimeException('The event loop did not provide a live fixture socket.');
    }

    return $value;
}
