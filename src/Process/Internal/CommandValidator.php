<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process\Internal;

use Infocyph\Runwire\Exception\ProcessStartException;
use Infocyph\Runwire\Process\Command;
use Infocyph\Runwire\Process\ProcessPolicy;

final readonly class CommandValidator
{
    public function __construct(private ProcessPolicy $policy) {}

    public function validate(Command $command): PreparedCommand
    {
        if ($command->timeoutSeconds > $this->policy->maxTimeoutSeconds) {
            throw new ProcessStartException('Command timeout exceeds the process policy ceiling.');
        }

        if ($command->terminationGraceSeconds > $this->policy->maxTerminationGraceSeconds) {
            throw new ProcessStartException('Termination grace exceeds the process policy ceiling.');
        }

        if ($command->maxOutputBytes > $this->policy->maxOutputBytes) {
            throw new ProcessStartException('Command output limit exceeds the process policy ceiling.');
        }

        if (is_string($command->stdin) && strlen($command->stdin) > $this->policy->maxStdinBytes) {
            throw new ProcessStartException('stdin exceeds the process policy byte limit.');
        }

        $executable = $this->executable($command->executable);
        $arguments = $this->arguments($command->arguments, strlen($executable) + 1);
        $environment = $this->environment($command->environment);
        $cwd = $this->cwd($command->cwd);

        return new PreparedCommand(
            command: $command,
            argv: [$executable, ...$arguments],
            environment: $environment,
            cwd: $cwd,
        );
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function arguments(array $arguments, int $totalBytes): array
    {
        if (count($arguments) > $this->policy->maxArgumentCount) {
            throw new ProcessStartException('Argument count exceeds the process policy limit.');
        }

        if ($totalBytes > $this->policy->maxArgvBytes) {
            throw new ProcessStartException('Combined argv exceeds the process policy byte limit.');
        }

        foreach ($arguments as $argument) {
            $bytes = strlen($argument);
            if (str_contains($argument, "\0")) {
                throw new ProcessStartException('Process arguments cannot contain NUL bytes.');
            }

            if ($bytes > $this->policy->maxArgumentBytes) {
                throw new ProcessStartException('A process argument exceeds the per-argument byte limit.');
            }

            $totalBytes += $bytes + 1;
            if ($totalBytes > $this->policy->maxArgvBytes) {
                throw new ProcessStartException('Combined argv exceeds the process policy byte limit.');
            }
        }

        return $arguments;
    }

    private function cwd(?string $cwd): ?string
    {
        if ($cwd === null) {
            return null;
        }

        $resolved = realpath($cwd);
        if ($resolved === false || !is_dir($resolved)) {
            throw new ProcessStartException(sprintf('Working directory "%s" does not exist.', $cwd));
        }

        foreach ($this->policy->allowedCwdRoots as $root) {
            $rootResolved = realpath($root);
            if ($rootResolved !== false && $this->inside($resolved, $rootResolved)) {
                return $resolved;
            }
        }

        throw new ProcessStartException(sprintf('Working directory "%s" is outside allowed roots.', $cwd));
    }

    /**
     * @param array<string, string> $environment
     * @return array<string, string>
     */
    private function environment(array $environment): array
    {
        if (count($environment) > $this->policy->maxEnvironmentCount) {
            throw new ProcessStartException('Environment entry count exceeds the process policy limit.');
        }

        $allowed = array_fill_keys($this->policy->allowedEnvironmentKeys, true);
        $totalBytes = 0;

        foreach ($environment as $name => $value) {
            if ($name === '' || str_contains($name, '=') || str_contains($name, "\0")) {
                throw new ProcessStartException(sprintf('Invalid environment variable name "%s".', $name));
            }

            if (!isset($allowed[$name])) {
                throw new ProcessStartException(sprintf('Environment variable "%s" is not allowed.', $name));
            }

            if (str_contains($value, "\0") || strlen($value) > $this->policy->maxEnvironmentValueBytes) {
                throw new ProcessStartException(sprintf('Environment variable "%s" exceeds policy.', $name));
            }

            $totalBytes += strlen($name) + strlen($value) + 2;
            if ($totalBytes > $this->policy->maxEnvironmentBytes) {
                throw new ProcessStartException('Combined environment exceeds the process policy byte limit.');
            }
        }

        return $environment;
    }

    private function executable(string $executable): string
    {
        if (!str_starts_with($executable, DIRECTORY_SEPARATOR)) {
            throw new ProcessStartException('Executable path must be absolute.');
        }

        $resolved = realpath($executable);
        if ($resolved === false || !is_file($resolved) || !is_executable($resolved)) {
            throw new ProcessStartException(sprintf('Executable "%s" is unavailable or not executable.', $executable));
        }

        $allowed = $this->policy->allowedExecutables;
        if ($allowed === null) {
            return $resolved;
        }

        foreach ($allowed as $candidate) {
            $candidateResolved = realpath($candidate);
            if ($candidateResolved !== false && hash_equals($candidateResolved, $resolved)) {
                return $resolved;
            }
        }

        throw new ProcessStartException(sprintf('Executable "%s" is not allowed by process policy.', $executable));
    }

    private function inside(string $path, string $root): bool
    {
        if ($root === DIRECTORY_SEPARATOR) {
            return str_starts_with($path, DIRECTORY_SEPARATOR);
        }

        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
