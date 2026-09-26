<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

use Infocyph\Runwire\Http\Headers;
use InvalidArgumentException;

/**
 * Centralizes protocol-independent final-response body semantics.
 */
final readonly class ResponseSemantics
{
    /**
     * Reject framing metadata that contradicts a content-free final response.
     */
    public static function assertContentLength(int $status, ?int $contentLength): void
    {
        if ($status === 205 && $contentLength !== null && $contentLength !== 0) {
            throw new InvalidArgumentException('HTTP 205 responses may declare only a zero Content-Length.');
        }
    }

    /**
     * Reject informational and invalid codes where the writer accepts one final response.
     */
    public static function assertFinalStatus(int $status): void
    {
        if ($status < 200 || $status > 599) {
            throw new InvalidArgumentException('Final HTTP response status must be between 200 and 599.');
        }
    }

    /**
     * Return one validated response Content-Length value when declared.
     */
    public static function contentLength(Headers $headers): ?int
    {
        $lengths = [];
        foreach ($headers->all('content-length') as $value) {
            $value = trim($value);
            if ($value === '' || preg_match('/^[0-9]+$/D', $value) !== 1) {
                throw new InvalidArgumentException('Invalid response Content-Length.');
            }

            $normalized = ltrim($value, '0');
            $normalized = $normalized === '' ? '0' : $normalized;
            $max = (string) PHP_INT_MAX;
            if (strlen($normalized) > strlen($max)
                || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
                throw new InvalidArgumentException('Response Content-Length exceeds platform integer range.');
            }

            $lengths[] = (int) $normalized;
        }

        if (count(array_unique($lengths, SORT_REGULAR)) > 1) {
            throw new InvalidArgumentException('Conflicting response Content-Length fields are forbidden.');
        }

        return $lengths[0] ?? null;
    }

    /**
     * Determine whether HTTP semantics prohibit response content on the wire.
     */
    public static function suppressesBody(bool $headRequest, int $status): bool
    {
        return $headRequest || in_array($status, [204, 205, 304], true);
    }
}
