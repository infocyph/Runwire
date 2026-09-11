<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process;

use InvalidArgumentException;

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

    /** @param array<string, string> $environment */
    public function environment(array $environment): self
    {
        return $this->copy(environment: $environment);
    }

    public function cwd(?string $cwd): self
    {
        return $this->copy(cwd: $cwd, replaceCwd: true);
    }

    public function stdin(mixed $stdin): self
    {
        return $this->copy(stdin: $stdin, replaceStdin: true);
    }

    public function output(IoMode $stdout, IoMode $stderr): self
    {
        return $this->copy(stdoutMode: $stdout, stderrMode: $stderr);
    }

    public function timeout(float $seconds): self
    {
        return $this->copy(timeoutSeconds: $seconds);
    }

    public function maxOutputBytes(int $bytes): self
    {
        return $this->copy(maxOutputBytes: $bytes);
    }

    public function terminationGrace(float $seconds): self
    {
        return $this->copy(terminationGraceSeconds: $seconds);
    }

    public function overflowPolicy(OutputOverflowPolicy $policy): self
    {
        return $this->copy(overflowPolicy: $policy);
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
