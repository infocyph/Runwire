<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop\Internal;

use RuntimeException;

/**
 * Classifies recoverable and permanent stream_select failures.
 *
 * @internal
 */
final readonly class SelectFailurePolicy
{
    /**
     * Return for recoverable interruption/pruning, otherwise fail closed.
     */
    public static function assertRecoverable(?string $warning, int $prunedWatchers): void
    {
        if ($prunedWatchers > 0) {
            return;
        }
        if ($warning !== null && str_contains($warning, 'Interrupted system call')) {
            return;
        }

        throw new RuntimeException($warning ?? 'stream_select() failed permanently.');
    }
}
