<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

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
     * Determine whether HTTP semantics prohibit response content on the wire.
     */
    public static function suppressesBody(bool $headRequest, int $status): bool
    {
        return $headRequest || in_array($status, [204, 205, 304], true);
    }
}
