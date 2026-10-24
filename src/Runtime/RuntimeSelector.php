<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Runtime\Enum\OpcacheMode;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeOptions;

/**
 * Resolves a runtime driver and validates capability-dependent runtime options.
 */
final readonly class RuntimeSelector
{
    /**
     * Creates a selector from driver and capability resolvers.
     */
    public function __construct(
        private RuntimeDriverResolver $driverResolver = new RuntimeDriverResolver(),
        private RuntimeCapabilityResolver $capabilityResolver = new RuntimeCapabilityResolver(),
    ) {}

    /**
     * Selects a concrete runtime and its capabilities for the environment.
     */
    public function select(RuntimeOptions $options, RuntimeEnvironment $environment): RuntimeSelection
    {
        $driver = $this->driverResolver->resolve($options, $environment);
        $warnings = $this->validateOpcache($options, $environment);
        if ($driver === RuntimeDriver::NATIVE && !$environment->nativePreforkEligible()) {
            $warnings[] = 'PCNTL/POSIX process capabilities are unavailable; using the single-process native runtime.';
        }
        $capabilities = $this->capabilityResolver->resolve($driver, $environment, $options);
        $this->validatePrivilegeDrop($options, $driver, $capabilities->supportsPrivilegeDrop);
        $warnings = [...$warnings, ...$this->nativeSecurityWarnings($options, $driver, $capabilities)];

        return new RuntimeSelection(
            driver: $driver,
            capabilities: $capabilities,
            warnings: $warnings,
        );
    }

    /** @return list<string> */
    private function nativeSecurityWarnings(
        RuntimeOptions $options,
        RuntimeDriver $driver,
        RuntimeCapabilities $capabilities,
    ): array {
        if (
            $driver !== RuntimeDriver::NATIVE
            || !$capabilities->ownsWorkerPool
            || $options->privilegeDrop->enabled()
            || !function_exists('posix_geteuid')
            || posix_geteuid() !== 0
        ) {
            return [];
        }

        return [
            'SECURITY: native prefork is running as root without a worker privilege-drop policy; application workers will execute as root.',
        ];
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

    private function validatePrivilegeDrop(RuntimeOptions $options, RuntimeDriver $driver, bool $supported): void
    {
        if (!$options->privilegeDrop->enabled()) {
            return;
        }
        if ($driver !== RuntimeDriver::NATIVE) {
            throw new RuntimeUnavailableException('Worker UID/GID changes are supported only by the native prefork runtime.');
        }
        if (!$supported) {
            throw new RuntimeUnavailableException('Worker UID/GID changes were requested but POSIX identity capabilities are unavailable.');
        }

        $options->privilegeDrop->assertSupported();
    }
}
