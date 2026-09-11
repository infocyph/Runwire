<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\RuntimeDriver;
use InvalidArgumentException;

final readonly class RuntimeEnvironment
{
    /** @var list<RuntimeDriver> */
    private array $hostedDrivers;

    /** @var list<RuntimeDriver> */
    private array $availableDrivers;

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
        public bool $opcacheAvailable = false,
        public bool $opcacheEnabled = false,
        public bool $opcacheCliEnabled = false,
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

    public function isHostedBy(RuntimeDriver $driver): bool
    {
        return in_array($driver, $this->hostedDrivers, true);
    }

    public function isAvailable(RuntimeDriver $driver): bool
    {
        return in_array($driver, $this->availableDrivers, true);
    }

    public function nativeEligible(): bool
    {
        return $this->sapi === 'cli'
            && $this->supportsFork
            && $this->supportsSignals
            && $this->supportsPosix;
    }

    /**
     * @return list<RuntimeDriver>
     */
    public function hostedDrivers(): array
    {
        return $this->hostedDrivers;
    }

    /**
     * @return list<RuntimeDriver>
     */
    public function availableDrivers(): array
    {
        return $this->availableDrivers;
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
