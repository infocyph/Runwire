<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\Network\SocketCapabilityProbe;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Internal\SystemResourceProbe;

/**
 * Detects runtime hosts, extensions, OPcache state, and system resources.
 */
final readonly class RuntimeEnvironmentProbe
{
    /**
     * Creates an environment probe using the supplied resource probe.
     */
    public function __construct(
        private SystemResourceProbe $resourceProbe = new SystemResourceProbe(),
    ) {}

    /**
     * Probes and returns the current runtime environment.
     */
    public function probe(): RuntimeEnvironment
    {
        $sapi = PHP_SAPI;
        $supportsFork = self::supportsFork();
        $supportsSignals = self::supportsSignals();
        $supportsPosix = self::supportsPosix();
        [$opcacheAvailable, $opcacheEnabled, $opcacheCliEnabled] = self::opcacheState($sapi);

        return new RuntimeEnvironment(
            sapi: $sapi,
            hostedDrivers: $this->hostedDrivers($sapi),
            availableDrivers: $this->availableDrivers($sapi, $supportsFork, $supportsSignals, $supportsPosix),
            supportsFork: $supportsFork,
            supportsSignals: $supportsSignals,
            supportsPosix: $supportsPosix,
            supportsOpenSsl: extension_loaded('openssl'),
            supportsQuic: self::supportsQuic(),
            frankenPhpWorkerMode: self::frankenPhpWorkerMode(),
            opcacheAvailable: $opcacheAvailable,
            opcacheEnabled: $opcacheEnabled,
            opcacheCliEnabled: $opcacheCliEnabled,
            supportsReusePort: SocketCapabilityProbe::supportsReusePort(),
            supportsUnixSockets: SocketCapabilityProbe::supportsUnixSockets(),
            supportsPrivilegeDrop: self::supportsPrivilegeDrop(),
            resources: $this->resourceProbe->probe(),
        );
    }

    private static function frankenPhpWorkerMode(): bool
    {
        $config = getenv('FRANKENPHP_CONFIG');

        return is_string($config) && preg_match('/(?:^|\s)worker(?:\s|$)/i', $config) === 1;
    }

    private static function iniFlag(string $name): bool
    {
        $value = ini_get($name);

        if ($value === false) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /** @return array{0: bool, 1: bool, 2: bool} */
    private static function opcacheState(string $sapi): array
    {
        $available = function_exists('opcache_get_status');
        $cliEnabled = $available && self::iniFlag('opcache.enable_cli');
        $enabled = $available
            && self::iniFlag('opcache.enable')
            && ($sapi !== 'cli' || $cliEnabled);

        return [$available, $enabled, $cliEnabled];
    }

    private static function supportsFork(): bool
    {
        return function_exists('pcntl_fork') && function_exists('pcntl_waitpid');
    }

    private static function supportsPosix(): bool
    {
        return extension_loaded('posix')
            && function_exists('posix_getpid')
            && function_exists('posix_kill');
    }

    private static function supportsPrivilegeDrop(): bool
    {
        return function_exists('posix_geteuid')
            && function_exists('posix_getegid')
            && function_exists('posix_setuid')
            && function_exists('posix_setgid');
    }

    private static function supportsQuic(): bool
    {
        return extension_loaded('quic')
            && class_exists('Quic\\Listener')
            && class_exists('Quic\\Connection')
            && class_exists('Quic\\Stream')
            && function_exists('Quic\\poll');
    }

    private static function supportsSignals(): bool
    {
        return function_exists('pcntl_signal')
            && function_exists('pcntl_async_signals')
            && function_exists('posix_kill');
    }

    /** @return list<RuntimeDriver> */
    private function availableDrivers(
        string $sapi,
        bool $supportsFork,
        bool $supportsSignals,
        bool $supportsPosix,
    ): array {
        $drivers = [];
        if ($sapi === 'cli' && $supportsFork && $supportsSignals && $supportsPosix) {
            $drivers[] = RuntimeDriver::NATIVE;
        }
        if (extension_loaded('swoole') || extension_loaded('openswoole')) {
            $drivers[] = RuntimeDriver::SWOOLE;
        }

        return $drivers;
    }

    /** @return list<RuntimeDriver> */
    private function hostedDrivers(string $sapi): array
    {
        $drivers = [];
        if ($this->isFrankenPhpHosted($sapi)) {
            $drivers[] = RuntimeDriver::FRANKENPHP;
        }
        if ($this->isRoadRunnerHosted()) {
            $drivers[] = RuntimeDriver::ROADRUNNER;
        }
        if ($this->isFpmHosted($sapi)) {
            $drivers[] = RuntimeDriver::FPM;
        }

        return $drivers;
    }

    private function isFpmHosted(string $sapi): bool
    {
        return str_contains(strtolower($sapi), 'fpm');
    }

    private function isFrankenPhpHosted(string $sapi): bool
    {
        if (str_contains(strtolower($sapi), 'frankenphp')) {
            return true;
        }

        return $sapi !== 'cli' && function_exists('frankenphp_handle_request');
    }

    private function isRoadRunnerHosted(): bool
    {
        $mode = $_SERVER['RR_MODE'] ?? getenv('RR_MODE');

        return is_string($mode) && $mode !== '';
    }
}
