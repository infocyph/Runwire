<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Http3Exception;

/**
 * Performs checked non-negative integer arithmetic for attacker-controlled QPACK values.
 *
 * @internal
 */
final class CheckedInteger
{
    /**
     * Add two non-negative integers or fail with the supplied HTTP/3 protocol error.
     */
    public static function add(int $left, int $right, ErrorCode $errorCode, string $message): int
    {
        if ($left < 0 || $right < 0 || $right > PHP_INT_MAX - $left) {
            throw new Http3Exception($errorCode, $message);
        }

        return $left + $right;
    }

    /**
     * Multiply two non-negative integers or fail with the supplied HTTP/3 protocol error.
     */
    public static function multiply(int $left, int $right, ErrorCode $errorCode, string $message): int
    {
        if ($left < 0 || $right < 0 || ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left))) {
            throw new Http3Exception($errorCode, $message);
        }

        return $left * $right;
    }
}
