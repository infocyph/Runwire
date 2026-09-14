<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;

/**
 * Selects a concrete runtime driver from explicit or automatic runtime policy.
 */
final class RuntimeDriverResolver
{
    private const array HOST_PRECEDENCE = [
        RuntimeDriver::FRANKENPHP,
        RuntimeDriver::SWOOLE,
        RuntimeDriver::ROADRUNNER,
        RuntimeDriver::FPM,
    ];

    /**
     * Resolves the runtime driver for the supplied options and environment.
     */
    public function resolve(RuntimeOptions $options, RuntimeEnvironment $environment): RuntimeDriver
    {
        if ($options->driver !== RuntimeDriver::AUTO) {
            return $this->resolveExplicit($options->driver, $environment);
        }

        foreach (self::HOST_PRECEDENCE as $driver) {
            if ($environment->isHostedBy($driver)) {
                return $driver;
            }
        }

        if ($environment->nativeEligible() && $environment->isAvailable(RuntimeDriver::NATIVE)) {
            return RuntimeDriver::NATIVE;
        }

        throw new RuntimeUnavailableException(sprintf(
            'Unable to resolve a Runwire runtime for SAPI "%s".',
            $environment->sapi,
        ));
    }

    private function resolveExplicit(RuntimeDriver $driver, RuntimeEnvironment $environment): RuntimeDriver
    {
        if ($driver === RuntimeDriver::NATIVE) {
            if ($environment->nativeEligible() && $environment->isAvailable($driver)) {
                return $driver;
            }

            throw new RuntimeUnavailableException(
                'The native runtime requires CLI with working PCNTL fork/signal and POSIX support.',
            );
        }

        if ($driver === RuntimeDriver::SWOOLE) {
            if ($environment->isAvailable($driver)) {
                return $driver;
            }

            throw new RuntimeUnavailableException('The Swoole/OpenSwoole runtime is not available.');
        }

        if ($environment->isHostedBy($driver)) {
            return $driver;
        }

        throw new RuntimeUnavailableException(sprintf(
            'The %s runtime is not the active host.',
            $driver->value,
        ));
    }
}
