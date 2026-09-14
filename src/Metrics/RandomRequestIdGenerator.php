<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

use Infocyph\Runwire\RuntimeContext;

/**
 * Generates compact random request identifiers scoped with runtime process metadata.
 */
final readonly class RandomRequestIdGenerator implements RequestIdGeneratorInterface
{
    /**
     * Generate a request identifier for the supplied runtime context.
     */
    public function generate(RuntimeContext $runtime): string
    {
        return substr(
            hash('sha256', random_bytes(16) . ':' . $runtime->pid . ':' . ($runtime->generation ?? 0)),
            0,
            32,
        );
    }
}
