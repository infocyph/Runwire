<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Support;

use RuntimeException;

/**
 * Validate the decoded map received over native-worker IPC.
 *
 * @return array<string,mixed>
 * @throws RuntimeException
 */
function nativeReply(mixed $value): array
{
    if (!is_array($value)) {
        throw new RuntimeException('Native worker returned a non-object reply.');
    }
    $reply = [];
    foreach ($value as $key => $field) {
        if (!is_string($key)) {
            throw new RuntimeException('Native worker reply requires named fields.');
        }
        $reply[$key] = $field;
    }

    return $reply;
}
