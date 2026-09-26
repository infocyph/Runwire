<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

use InvalidArgumentException;
use OverflowException;

/**
 * Parses decimal Content-Length values with platform-safe integer bounds.
 */
final readonly class ContentLengthParser
{
    /**
     * Parse one Content-Length field value.
     *
     * @throws InvalidArgumentException when the field is not an unsigned decimal integer
     * @throws OverflowException when the value exceeds the platform integer range
     */
    public static function parse(string $value): int
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('Content-Length must be an unsigned decimal integer.');
        }

        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $max = (string) PHP_INT_MAX;
        if (
            strlen($normalized) > strlen($max)
            || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)
        ) {
            throw new OverflowException('Content-Length exceeds platform integer range.');
        }

        return (int) $normalized;
    }
}
