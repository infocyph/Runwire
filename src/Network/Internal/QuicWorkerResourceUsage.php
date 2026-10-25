<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

/**
 * @internal Read Linux process usage only when native QUIC profiling is opted in.
 *
 * ru_maxrss is in KiB on Linux, unlike PHP heap allocations. Never called in
 * normal native QUIC read/write/poll loops.
 */
final class QuicWorkerResourceUsage
{
    /** @return array{cpu_us:int,rss_kib:int,available:bool} */
    public static function snapshot(): array
    {
        $usage = function_exists('getrusage') ? getrusage() : false;
        if (!is_array($usage)) {
            return ['cpu_us' => 0, 'rss_kib' => 0, 'available' => false];
        }

        $userSeconds = self::integer($usage['ru_utime.tv_sec'] ?? null);
        $userMicroseconds = self::integer($usage['ru_utime.tv_usec'] ?? null);
        $systemSeconds = self::integer($usage['ru_stime.tv_sec'] ?? null);
        $systemMicroseconds = self::integer($usage['ru_stime.tv_usec'] ?? null);

        return [
            'cpu_us' => ($userSeconds + $systemSeconds) * 1_000_000
                + $userMicroseconds + $systemMicroseconds,
            'rss_kib' => self::integer($usage['ru_maxrss'] ?? null),
            'available' => true,
        ];
    }

    private static function integer(mixed $value): int
    {
        return is_int($value) ? max(0, $value) : 0;
    }
}
