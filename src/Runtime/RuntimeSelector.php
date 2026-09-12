<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\OpcacheMode;
use Infocyph\Runwire\RuntimeOptions;

final readonly class RuntimeSelector
{
    public function __construct(
        private RuntimeDriverResolver $driverResolver = new RuntimeDriverResolver(),
        private RuntimeCapabilityResolver $capabilityResolver = new RuntimeCapabilityResolver(),
    ) {}

    public function select(RuntimeOptions $options, RuntimeEnvironment $environment): RuntimeSelection
    {
        $driver = $this->driverResolver->resolve($options, $environment);
        $warnings = $this->validateOpcache($options, $environment);

        return new RuntimeSelection(
            driver: $driver,
            capabilities: $this->capabilityResolver->resolve($driver, $environment, $options),
            warnings: $warnings,
        );
    }

    /** @return list<string> */
    private function validateOpcache(RuntimeOptions $options, RuntimeEnvironment $environment): array
    {
        if ($options->opcache === OpcacheMode::OFF || $options->opcache === OpcacheMode::AUTO) {
            return [];
        }

        if ($environment->opcacheEnabled) {
            return [];
        }

        if ($options->opcache === OpcacheMode::REQUIRED) {
            throw new RuntimeUnavailableException(
                'OPcache is required but is unavailable or disabled for the active PHP SAPI.',
            );
        }

        return ['OPcache was requested but is unavailable or disabled for the active PHP SAPI.'];
    }
}
