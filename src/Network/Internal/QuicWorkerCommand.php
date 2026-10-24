<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use InvalidArgumentException;
use JsonException;

/**
 * @internal Bounded command envelope for a future persistent native-QUIC worker.
 *
 * Wire data is JSON with a strict base64 payload and one trailing newline.
 * A worker owns its native Connection/Stream instances; only opaque numeric
 * session/stream identifiers may cross the nonblocking Runwire IPC boundary.
 */
final class QuicWorkerCommand
{
    public const int MAX_DATA_BYTES = 65_536;

    private const array COMMANDS = [
        'open_bidi',
        'open_uni',
        'write',
        'read',
        'reset',
        'close',
    ];

    private const int MAX_WIRE_BYTES = 90_000;

    /**
     * Decode and revalidate an untrusted command received over worker IPC.
     *
     * @return array{id:int, op:string, stream:int, data:string, fin:bool}
     *
     * @throws JsonException
     */
    public static function decode(string $packet): array
    {
        if ($packet === '' || strlen($packet) > self::MAX_WIRE_BYTES
            || !str_ends_with($packet, "\n")) {
            throw new InvalidArgumentException('Invalid QUIC worker command envelope.');
        }
        $message = json_decode(substr($packet, 0, -1), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($message) || count($message) !== 5
            || !isset($message['id'], $message['op'], $message['stream'], $message['data'], $message['fin'])
            || !is_int($message['id']) || !is_string($message['op'])
            || !is_int($message['stream']) || !is_string($message['data'])
            || !is_bool($message['fin'])) {
            throw new InvalidArgumentException('Invalid QUIC worker command fields.');
        }

        $data = base64_decode($message['data'], true);
        if (!is_string($data)) {
            throw new InvalidArgumentException('Invalid QUIC worker binary payload.');
        }
        self::assertCommand($message['id'], $message['op'], $message['stream'], $data, $message['fin']);

        return [
            'id' => $message['id'],
            'op' => $message['op'],
            'stream' => $message['stream'],
            'data' => $data,
            'fin' => $message['fin'],
        ];
    }

    /**
     * Encode exactly one bounded stream command, never a native resource.
     */
    public static function encode(
        int $requestId,
        string $operation,
        int $streamId = 0,
        string $body = '',
        bool $fin = false,
    ): string {
        self::assertCommand($requestId, $operation, $streamId, $body, $fin);

        return json_encode([
            'id' => $requestId,
            'op' => $operation,
            'stream' => $streamId,
            'data' => base64_encode($body),
            'fin' => $fin,
        ], JSON_THROW_ON_ERROR) . "\n";
    }

    private static function assertCommand(
        int $requestId,
        string $operation,
        int $streamId,
        string $body,
        bool $fin,
    ): void {
        if ($requestId < 1 || $streamId < 0 || !in_array($operation, self::COMMANDS, true)
            || strlen($body) > self::MAX_DATA_BYTES
            || ($operation !== 'write' && $body !== '')
            || (in_array($operation, ['open_bidi', 'open_uni', 'close'], true) && $streamId !== 0)
            || (in_array($operation, ['write', 'read', 'reset'], true) && $streamId === 0)
            || ($operation !== 'write' && $fin)) {
            throw new InvalidArgumentException('Invalid or excessive QUIC worker command.');
        }
    }
}
