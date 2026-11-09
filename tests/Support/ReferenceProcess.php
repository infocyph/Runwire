<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Support;

use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * @param resource $pipe
 * @throws RuntimeException
 */
function readReferencePipe(mixed $pipe): string
{
    $bytes = stream_get_contents($pipe);
    if ($bytes === false) {
        throw new RuntimeException('Independent reference process pipe read failed.');
    }

    return $bytes;
}

/**
 * @param resource $process
 * @param resource $output
 * @param resource $errors
 * @param non-empty-string $pattern
 * @return list<string>
 * @throws RuntimeException
 */
function awaitReferenceReady(mixed $process, mixed $output, mixed $errors, float $seconds, string $pattern, string $message, int $pollMicroseconds = 10_000): array
{
    $ready = '';
    $started = microtime(true);
    while (!str_contains($ready, "\n") && microtime(true) - $started < $seconds) {
        $ready .= readReferencePipe($output);
        if (str_contains($ready, "\n") || !proc_get_status($process)['running']) {
            break;
        }
        usleep($pollMicroseconds);
    }
    if (preg_match($pattern, $ready, $matches) !== 1) {
        throw new RuntimeException($message . readReferencePipe($errors));
    }

    return array_values($matches);
}

/**
 * @param resource $process
 * @param resource $output
 * @throws RuntimeException
 */
function awaitReferenceExit(mixed $process, mixed $output, float $seconds): string
{
    $bytes = '';
    $status = null;
    $started = microtime(true);
    while (microtime(true) - $started < $seconds) {
        $bytes .= readReferencePipe($output);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(10_000);
    }
    $bytes .= readReferencePipe($output);
    Assert::assertFalse($status['running'] ?? true);

    return $bytes;
}
