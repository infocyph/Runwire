<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Runtime\Driver\FpmDriver;
use Infocyph\Runwire\Runtime\Driver\FrankenPhpDriver;
use Infocyph\Runwire\Runtime\Driver\RoadRunnerDriver;
use Infocyph\Runwire\Runtime\Driver\SwooleDriver;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;

/**
 * Creates host-owned runtime driver implementations from resolved driver selections.
 */
final class HostDriverFactory
{
    /**
     * Creates the host driver corresponding to the resolved runtime type.
     */
    public function create(RuntimeDriver $driver, RuntimeOptions $options): HostDriverInterface
    {
        return match ($driver) {
            RuntimeDriver::FPM => new FpmDriver($options->fpm),
            RuntimeDriver::FRANKENPHP => new FrankenPhpDriver(
                $options->frankenPhp,
                recyclePolicy: $options->workerRecycle,
            ),
            RuntimeDriver::ROADRUNNER => new RoadRunnerDriver(
                $options->roadRunner,
                recyclePolicy: $options->workerRecycle,
            ),
            RuntimeDriver::SWOOLE => new SwooleDriver(
                $options->swoole,
                recyclePolicy: $options->workerRecycle,
            ),
            RuntimeDriver::AUTO, RuntimeDriver::NATIVE => throw new RuntimeUnavailableException(sprintf(
                'Runtime driver "%s" is not a host-owned serve() driver.',
                $driver->value,
            )),
        };
    }
}
