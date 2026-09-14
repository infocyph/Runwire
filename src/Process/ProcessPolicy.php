<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process;

use InvalidArgumentException;

/**
 * Defines security and resource ceilings for child-process execution.
 */
final readonly class ProcessPolicy
{
    /**
     * @param list<string>|null $allowedExecutables null allows any absolute executable
     * @param list<string> $allowedEnvironmentKeys empty forbids explicit environment variables
     * @param list<string> $allowedCwdRoots empty forbids cwd overrides
     */
    public function __construct(
        public ?array $allowedExecutables = null,
        public array $allowedEnvironmentKeys = [],
        public array $allowedCwdRoots = [],
        public int $maxArgumentCount = 256,
        public int $maxArgumentBytes = 65_536,
        public int $maxArgvBytes = 262_144,
        public int $maxEnvironmentCount = 64,
        public int $maxEnvironmentValueBytes = 65_536,
        public int $maxEnvironmentBytes = 262_144,
        public int $maxStdinBytes = 8_388_608,
        public int $maxOutputBytes = 67_108_864,
        public float $maxTimeoutSeconds = 3_600.0,
        public float $maxTerminationGraceSeconds = 10.0,
        public float $postExitDrainSeconds = 0.25,
    ) {
        self::validateIntegerLimits([
            'maxArgumentCount' => $maxArgumentCount,
            'maxArgumentBytes' => $maxArgumentBytes,
            'maxArgvBytes' => $maxArgvBytes,
            'maxEnvironmentCount' => $maxEnvironmentCount,
            'maxEnvironmentValueBytes' => $maxEnvironmentValueBytes,
            'maxEnvironmentBytes' => $maxEnvironmentBytes,
            'maxStdinBytes' => $maxStdinBytes,
            'maxOutputBytes' => $maxOutputBytes,
        ]);
        self::validateTimeLimits($maxTimeoutSeconds, $maxTerminationGraceSeconds, $postExitDrainSeconds);
        self::validateExecutables($allowedExecutables ?? []);
        self::validateEnvironmentKeys($allowedEnvironmentKeys);
        self::validateCwdRoots($allowedCwdRoots);
    }

    /** @param list<string> $roots */
    private static function validateCwdRoots(array $roots): void
    {
        foreach ($roots as $root) {
            if ($root === '' || str_contains($root, "\0")) {
                throw new InvalidArgumentException('Allowed cwd roots must be non-empty strings without NUL bytes.');
            }
        }
    }

    /** @param list<string> $keys */
    private static function validateEnvironmentKeys(array $keys): void
    {
        foreach ($keys as $key) {
            if ($key === '' || str_contains($key, '=') || str_contains($key, "\0")) {
                throw new InvalidArgumentException('Allowed environment keys must be valid non-empty names.');
            }
        }
    }

    /** @param list<string> $executables */
    private static function validateExecutables(array $executables): void
    {
        foreach ($executables as $executable) {
            if ($executable === '' || str_contains($executable, "\0")) {
                throw new InvalidArgumentException('Allowed executable paths must be non-empty strings without NUL bytes.');
            }
        }
    }

    /** @param array<string, int> $limits */
    private static function validateIntegerLimits(array $limits): void
    {
        foreach ($limits as $name => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException(sprintf('%s cannot be negative.', $name));
            }
        }
    }

    private static function validateTimeLimits(
        float $maxTimeoutSeconds,
        float $maxTerminationGraceSeconds,
        float $postExitDrainSeconds,
    ): void {
        foreach ([
            'maxTimeoutSeconds' => $maxTimeoutSeconds,
            'maxTerminationGraceSeconds' => $maxTerminationGraceSeconds,
            'postExitDrainSeconds' => $postExitDrainSeconds,
        ] as $name => $value) {
            if (!is_finite($value) || $value < 0) {
                throw new InvalidArgumentException(sprintf('%s must be finite and non-negative.', $name));
            }
        }
        if ($maxTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('maxTimeoutSeconds must be positive.');
        }
    }
}
