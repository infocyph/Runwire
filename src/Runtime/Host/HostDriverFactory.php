<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Runtime\Driver\FpmDriver;
use Infocyph\Runwire\Runtime\Driver\FrankenPhpDriver;
use Infocyph\Runwire\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;

final class HostDriverFactory
{
    public function create(RuntimeDriver $driver, RuntimeOptions $options): HostDriverInterface
    {
        return match ($driver) {
            RuntimeDriver::FPM => new FpmDriver($options->fpm),
            RuntimeDriver::FRANKENPHP => new FrankenPhpDriver($options->frankenPhp),
            RuntimeDriver::SWOOLE, RuntimeDriver::ROADRUNNER => throw new RuntimeUnavailableException(sprintf(
                'Runtime driver "%s" is selected, but its host execution adapter is not wired yet.',
                $driver->value,
            )),
            RuntimeDriver::AUTO, RuntimeDriver::NATIVE => throw new RuntimeUnavailableException(sprintf(
                'Runtime driver "%s" is not a host-owned serve() driver.',
                $driver->value,
            )),
        };
    }
}
