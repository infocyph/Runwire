<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\RuntimeDriver;

final class RuntimeEnvironmentProbe
{
    public function probe(): RuntimeEnvironment
    {
        $sapi = PHP_SAPI;
        $supportsFork = function_exists('pcntl_fork') && function_exists('pcntl_waitpid');
        $supportsSignals = function_exists('pcntl_signal')
            && function_exists('pcntl_async_signals')
            && function_exists('posix_kill');
        $supportsPosix = extension_loaded('posix')
            && function_exists('posix_getpid')
            && function_exists('posix_kill');

        $hosted = [];
        $available = [];

        if ($this->isFrankenPhpHosted($sapi)) {
            $hosted[] = RuntimeDriver::FRANKENPHP;
        }

        if ($this->isRoadRunnerHosted()) {
            $hosted[] = RuntimeDriver::ROADRUNNER;
        }

        if ($this->isFpmHosted($sapi)) {
            $hosted[] = RuntimeDriver::FPM;
        }

        if ($sapi === 'cli' && $supportsFork && $supportsSignals && $supportsPosix) {
            $available[] = RuntimeDriver::NATIVE;
        }

        if (extension_loaded('swoole') || extension_loaded('openswoole')) {
            $available[] = RuntimeDriver::SWOOLE;
        }

        $opcacheAvailable = function_exists('opcache_get_status');
        $opcacheCliEnabled = $opcacheAvailable && self::iniFlag('opcache.enable_cli');
        $opcacheEnabled = $opcacheAvailable
            && self::iniFlag('opcache.enable')
            && ($sapi !== 'cli' || $opcacheCliEnabled);

        return new RuntimeEnvironment(
            sapi: $sapi,
            hostedDrivers: $hosted,
            availableDrivers: $available,
            supportsFork: $supportsFork,
            supportsSignals: $supportsSignals,
            supportsPosix: $supportsPosix,
            supportsOpenSsl: extension_loaded('openssl'),
            opcacheAvailable: $opcacheAvailable,
            opcacheEnabled: $opcacheEnabled,
            opcacheCliEnabled: $opcacheCliEnabled,
        );
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

    private function isFpmHosted(string $sapi): bool
    {
        return str_contains(strtolower($sapi), 'fpm');
    }

    private static function iniFlag(string $name): bool
    {
        $value = ini_get($name);

        if ($value === false) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
