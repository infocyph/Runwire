<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeDriver;

final class RuntimeCapabilityResolver
{
    public function resolve(RuntimeDriver $driver, RuntimeEnvironment $environment): RuntimeCapabilities
    {
        return match ($driver) {
            RuntimeDriver::NATIVE => $this->native($environment),
            RuntimeDriver::FPM => $this->fpm($environment),
            RuntimeDriver::FRANKENPHP => $this->frankenPhp($environment),
            RuntimeDriver::SWOOLE => $this->swoole($environment),
            RuntimeDriver::ROADRUNNER => $this->roadRunner($environment),
            RuntimeDriver::AUTO => throw new \LogicException('AUTO must be resolved before capabilities are built.'),
        };
    }

    private function native(RuntimeEnvironment $environment): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            driver: RuntimeDriver::NATIVE,
            persistentProcess: true,
            persistentApplication: true,
            ownsListener: true,
            ownsEventLoop: true,
            ownsWorkerPool: true,
            supportsFork: $environment->supportsFork,
            supportsSignals: $environment->supportsSignals,
            supportsAsyncIo: true,
            supportsGracefulReload: $environment->supportsSignals,
            supportsHttp1: true,
            supportsHttp2: true,
            ownsHttp1Wire: true,
            ownsHttp2Wire: true,
            supportsTlsAlpn: $environment->supportsOpenSsl,
            supportsOpcache: $environment->opcacheAvailable,
            supportsOpcacheCli: $environment->opcacheCliEnabled,
        );
    }

    private function fpm(RuntimeEnvironment $environment): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            driver: RuntimeDriver::FPM,
            persistentProcess: true,
            persistentApplication: false,
            supportsOpcache: $environment->opcacheAvailable,
            supportsOpcacheCli: false,
        );
    }

    private function frankenPhp(RuntimeEnvironment $environment): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            driver: RuntimeDriver::FRANKENPHP,
            persistentProcess: true,
            persistentApplication: false,
            supportsOpcache: $environment->opcacheAvailable,
            supportsOpcacheCli: false,
        );
    }

    private function swoole(RuntimeEnvironment $environment): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            driver: RuntimeDriver::SWOOLE,
            persistentProcess: true,
            persistentApplication: true,
            supportsOpcache: $environment->opcacheAvailable,
            supportsOpcacheCli: $environment->opcacheCliEnabled,
        );
    }

    private function roadRunner(RuntimeEnvironment $environment): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            driver: RuntimeDriver::ROADRUNNER,
            persistentProcess: true,
            persistentApplication: true,
            supportsOpcache: $environment->opcacheAvailable,
            supportsOpcacheCli: $environment->opcacheCliEnabled,
        );
    }
}
