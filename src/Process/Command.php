<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process;

use Infocyph\Runwire\Process\Enum\IoMode;
use Infocyph\Runwire\Process\Enum\OutputOverflowPolicy;
use InvalidArgumentException;

/**
 * Immutable child-process command definition with fluent copy helpers.
 */
final readonly class Command
{
    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     */
    public function __construct(
        public string $executable,
        public array $arguments = [],
        public array $environment = [],
        public ?string $cwd = null,
        public mixed $stdin = null,
        public IoMode $stdoutMode = IoMode::CAPTURE,
        public IoMode $stderrMode = IoMode::CAPTURE,
        public float $timeoutSeconds = 30.0,
        public int $maxOutputBytes = 1_048_576,
        public float $terminationGraceSeconds = 1.0,
        public OutputOverflowPolicy $overflowPolicy = OutputOverflowPolicy::TERMINATE,
    ) {
        if ($executable === '') {
            throw new InvalidArgumentException('Executable cannot be empty.');
        }

        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0) {
            throw new InvalidArgumentException('Process timeout must be finite and positive.');
        }

        if ($maxOutputBytes < 0) {
            throw new InvalidArgumentException('Maximum output bytes cannot be negative.');
        }

        if (!is_finite($terminationGraceSeconds) || $terminationGraceSeconds < 0) {
            throw new InvalidArgumentException('Termination grace must be finite and non-negative.');
        }

        if ($stdin !== null && !is_string($stdin) && !is_resource($stdin) && !is_callable($stdin)) {
            throw new InvalidArgumentException('stdin must be null, a string, stream resource or callable chunk producer.');
        }
        if (is_resource($stdin) && get_resource_type($stdin) !== 'stream') {
            throw new InvalidArgumentException('stdin resource must be a stream.');
        }
    }

    /** @param list<string> $arguments */
    public static function executable(string $executable, array $arguments = []): self
    {
        return new self($executable, $arguments);
    }

    /** @param list<string> $arguments */
    public function arguments(array $arguments): self
    {
        return $this->copy(arguments: $arguments);
    }

    /**
     * Returns a copy using the supplied working directory.
     */
    public function cwd(?string $cwd): self
    {
        return $this->copy(cwd: $cwd, replaceCwd: true);
    }

    /** @param array<string, string> $environment */
    public function environment(array $environment): self
    {
        return $this->copy(environment: $environment);
    }

    /**
     * Returns a copy using the supplied output byte ceiling.
     */
    public function maxOutputBytes(int $bytes): self
    {
        return $this->copy(maxOutputBytes: $bytes);
    }

    /**
     * Returns a copy using the supplied stdout and stderr modes.
     */
    public function output(IoMode $stdout, IoMode $stderr): self
    {
        return $this->copy(stdoutMode: $stdout, stderrMode: $stderr);
    }

    /**
     * Returns a copy using the supplied output-overflow policy.
     */
    public function overflowPolicy(OutputOverflowPolicy $policy): self
    {
        return $this->copy(overflowPolicy: $policy);
    }

    /**
     * Returns a copy using the supplied stdin source.
     */
    public function stdin(mixed $stdin): self
    {
        return $this->copy(stdin: $stdin, replaceStdin: true);
    }

    /**
     * Returns a copy using the supplied termination grace period.
     */
    public function terminationGrace(float $seconds): self
    {
        return $this->copy(terminationGraceSeconds: $seconds);
    }

    /**
     * Returns a copy using the supplied execution timeout.
     */
    public function timeout(float $seconds): self
    {
        return $this->copy(timeoutSeconds: $seconds);
    }

    /**
     * @param list<string>|null $arguments
     * @param array<string, string>|null $environment
     */
    private function copy(
        ?array $arguments = null,
        ?array $environment = null,
        ?string $cwd = null,
        bool $replaceCwd = false,
        mixed $stdin = null,
        bool $replaceStdin = false,
        ?IoMode $stdoutMode = null,
        ?IoMode $stderrMode = null,
        ?float $timeoutSeconds = null,
        ?int $maxOutputBytes = null,
        ?float $terminationGraceSeconds = null,
        ?OutputOverflowPolicy $overflowPolicy = null,
    ): self {
        return new self(
            executable: $this->executable,
            arguments: $arguments ?? $this->arguments,
            environment: $environment ?? $this->environment,
            cwd: $replaceCwd ? $cwd : ($cwd ?? $this->cwd),
            stdin: $replaceStdin ? $stdin : ($stdin ?? $this->stdin),
            stdoutMode: $stdoutMode ?? $this->stdoutMode,
            stderrMode: $stderrMode ?? $this->stderrMode,
            timeoutSeconds: $timeoutSeconds ?? $this->timeoutSeconds,
            maxOutputBytes: $maxOutputBytes ?? $this->maxOutputBytes,
            terminationGraceSeconds: $terminationGraceSeconds ?? $this->terminationGraceSeconds,
            overflowPolicy: $overflowPolicy ?? $this->overflowPolicy,
        );
    }
}
