<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use InvalidArgumentException;

/**
 * Describes effective CPU and memory resources available to the runtime.
 */
final readonly class SystemResources
{
    /**
     * Creates an effective system resource snapshot.
     */
    public function __construct(
        public int $effectiveCpuCount = 1,
        public ?int $effectiveMemoryBytes = null,
    ) {
        if ($effectiveCpuCount < 1 || $effectiveCpuCount > 65_536) {
            throw new InvalidArgumentException('Effective CPU count must be between 1 and 65536.');
        }
        if ($effectiveMemoryBytes !== null && $effectiveMemoryBytes < 1) {
            throw new InvalidArgumentException('Effective memory bytes must be null or positive.');
        }
    }

    /**
     * Resolves a configured or automatic worker count under the supplied maximum.
     */
    public function resolveWorkerCount(int $configured, int $maximum = 1_024): int
    {
        if ($configured < 0) {
            throw new InvalidArgumentException('Configured worker count cannot be negative.');
        }
        if ($maximum < 1) {
            throw new InvalidArgumentException('Maximum worker count must be positive.');
        }

        if ($configured > 0) {
            return min($configured, $maximum);
        }

        return min($this->effectiveCpuCount, $maximum);
    }
}
