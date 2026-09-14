<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use InvalidArgumentException;

/**
 * Describes detected host runtime drivers, extensions, and effective system resources.
 */
final readonly class RuntimeEnvironment
{
    /** @var list<RuntimeDriver> */
    private array $availableDrivers;

    /** @var list<RuntimeDriver> */
    private array $hostedDrivers;

    /**
     * @param list<RuntimeDriver> $hostedDrivers
     * @param list<RuntimeDriver> $availableDrivers
     */
    public function __construct(
        public string $sapi,
        array $hostedDrivers = [],
        array $availableDrivers = [],
        public bool $supportsFork = false,
        public bool $supportsSignals = false,
        public bool $supportsPosix = false,
        public bool $supportsOpenSsl = false,
        public bool $supportsQuic = false,
        public bool $frankenPhpWorkerMode = false,
        public bool $opcacheAvailable = false,
        public bool $opcacheEnabled = false,
        public bool $opcacheCliEnabled = false,
        public bool $supportsReusePort = false,
        public bool $supportsUnixSockets = false,
        public bool $supportsPrivilegeDrop = false,
        public SystemResources $resources = new SystemResources(),
    ) {
        if ($sapi === '') {
            throw new InvalidArgumentException('SAPI name cannot be empty.');
        }

        $this->hostedDrivers = self::normalizeDrivers($hostedDrivers);
        $this->availableDrivers = self::normalizeDrivers([
            ...$availableDrivers,
            ...$this->hostedDrivers,
        ]);
    }

    /** @return list<RuntimeDriver> */
    public function availableDrivers(): array
    {
        return $this->availableDrivers;
    }

    /** @return list<RuntimeDriver> */
    public function hostedDrivers(): array
    {
        return $this->hostedDrivers;
    }

    /**
     * Reports whether a runtime driver is available in this environment.
     */
    public function isAvailable(RuntimeDriver $driver): bool
    {
        return in_array($driver, $this->availableDrivers, true);
    }

    /**
     * Reports whether the current process is hosted by the supplied driver.
     */
    public function isHostedBy(RuntimeDriver $driver): bool
    {
        return in_array($driver, $this->hostedDrivers, true);
    }

    /**
     * Reports whether the environment can run the native prefork runtime.
     */
    public function nativeEligible(): bool
    {
        return $this->sapi === 'cli'
            && $this->supportsFork
            && $this->supportsSignals
            && $this->supportsPosix;
    }

    /**
     * @param list<RuntimeDriver> $drivers
     * @return list<RuntimeDriver>
     */
    private static function normalizeDrivers(array $drivers): array
    {
        $normalized = [];

        foreach ($drivers as $driver) {
            if ($driver === RuntimeDriver::AUTO) {
                throw new InvalidArgumentException('AUTO is a selection policy, not a runtime capability.');
            }

            $normalized[$driver->value] = $driver;
        }

        return array_values($normalized);
    }
}
