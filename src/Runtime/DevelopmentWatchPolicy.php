<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use InvalidArgumentException;

final readonly class DevelopmentWatchPolicy
{
    private const int MAX_FILES = 16_384;

    private const int MAX_ROOTS = 32;

    /** @param list<string> $paths */
    public function __construct(
        public bool $enabled = false,
        public array $paths = [],
        public float $pollIntervalSeconds = 0.5,
        public float $debounceSeconds = 0.25,
        public int $maxFiles = 4_096,
    ) {
        if ($enabled && $paths === []) {
            throw new InvalidArgumentException('Enabled development watcher requires at least one path.');
        }
        if (count($paths) > self::MAX_ROOTS || count(array_unique($paths)) !== count($paths)) {
            throw new InvalidArgumentException('Development watcher paths must be unique and contain at most 32 roots.');
        }
        foreach ($paths as $path) {
            if ($path === '' || strlen($path) > 4_096 || str_contains($path, "\0")) {
                throw new InvalidArgumentException('Development watcher paths must be non-empty strings of at most 4096 bytes.');
            }
        }
        if (!is_finite($pollIntervalSeconds) || $pollIntervalSeconds < 0.05 || $pollIntervalSeconds > 60.0) {
            throw new InvalidArgumentException('Development watcher poll interval must be between 0.05 and 60 seconds.');
        }
        if (!is_finite($debounceSeconds) || $debounceSeconds < 0.01 || $debounceSeconds > 10.0) {
            throw new InvalidArgumentException('Development watcher debounce must be between 0.01 and 10 seconds.');
        }
        if ($maxFiles < 1 || $maxFiles > self::MAX_FILES) {
            throw new InvalidArgumentException(sprintf('Development watcher maxFiles must be between 1 and %d.', self::MAX_FILES));
        }
    }
}
