<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

/**
 * Performs stream writes while containing expected fwrite warnings to the write attempt.
 *
 * @internal
 */
final class StreamWriter
{
    /**
     * @param resource $stream
     * @param int<0, max>|null $length
     */
    public static function write(mixed $stream, string $data, ?int $length = null): int|false
    {
        set_error_handler(self::writeWarning(...));

        try {
            return $length === null
                ? fwrite($stream, $data)
                : fwrite($stream, $data, $length);
        } finally {
            restore_error_handler();
        }
    }

    private static function writeWarning(int $severity, string $message): bool
    {
        return $severity === E_WARNING && str_starts_with($message, 'fwrite():');
    }
}
