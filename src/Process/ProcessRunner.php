<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process;

use Closure;
use Infocyph\Runwire\Exception\ProcessStartException;
use Infocyph\Runwire\Internal\MonotonicTime;
use Infocyph\Runwire\Process\Enum\IoMode;
use Infocyph\Runwire\Process\Internal\CommandValidator;
use Infocyph\Runwire\Process\Internal\InputSource;
use Infocyph\Runwire\Process\Internal\OutputSink;
use Infocyph\Runwire\Process\Internal\PreparedCommand;
use Infocyph\Runwire\Process\Internal\ProcessHandle;
use Infocyph\Runwire\Process\Internal\ProcessTermination;
use Throwable;

/**
 * Executes validated child-process commands with bounded I/O and termination handling.
 */
final readonly class ProcessRunner
{
    private const string NULL_DEVICE = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

    private const int IO_CHUNK_BYTES = 65_536;

    private const int POLL_MICROS = 50_000;

    private ProcessPolicy $policy;

    private CommandValidator $validator;

    /**
     * Creates a process runner using the supplied execution policy.
     */
    public function __construct(?ProcessPolicy $policy = null)
    {
        $this->policy = $policy ?? new ProcessPolicy();
        $this->validator = new CommandValidator($this->policy);
    }

    /**
     * Executes a command and returns its captured execution result.
     */
    public function run(Command $command, ?callable $stdoutConsumer = null, ?callable $stderrConsumer = null): ProcessResult
    {
        $this->ensureProcessFunctions();
        ProcessHandle::reapDetached();
        $prepared = $this->validator->validate($command);
        $this->validateConsumer($command->stdoutMode, $stdoutConsumer, 'stdout');
        $this->validateConsumer($command->stderrMode, $stderrConsumer, 'stderr');

        $input = new InputSource($command->stdin, $this->policy->maxStdinBytes);
        $handle = null;
        $pipes = [];

        try {
            $stdout = new OutputSink($command->stdoutMode, self::closure($stdoutConsumer));
            $stderr = new OutputSink($command->stderrMode, self::closure($stderrConsumer));
            [$handle, $pipes] = $this->start($prepared);

            return $this->execute($prepared, $handle, $pipes, $input, $stdout, $stderr);
        } catch (Throwable $exception) {
            $handle?->abort();

            throw $exception;
        } finally {
            $input->close();
            $this->closePipes($pipes);
            $handle?->close(wait: false);
        }
    }

    private static function closure(?callable $consumer): ?Closure
    {
        return $consumer === null ? null : Closure::fromCallable($consumer);
    }

    /** @param array<int, resource> $pipes */
    private function closePipe(array &$pipes, int $index): void
    {
        if (isset($pipes[$index]) && is_resource($pipes[$index])) {
            fclose($pipes[$index]);
        }
        unset($pipes[$index]);
    }

    /** @param array<int, resource> $pipes */
    private function closePipes(array &$pipes): void
    {
        foreach (array_keys($pipes) as $index) {
            $this->closePipe($pipes, $index);
        }
    }

    private function ensureProcessFunctions(): void
    {
        foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $function) {
            if (!function_exists($function)) {
                throw new ProcessStartException(sprintf('Required process function "%s" is unavailable or disabled.', $function));
            }
        }
    }

    /** @param array<int, resource> $pipes */
    private function execute(PreparedCommand $prepared, ProcessHandle $process, array &$pipes, InputSource $input, OutputSink $stdout, OutputSink $stderr): ProcessResult
    {
        $child = $process->resource();
        $command = $prepared->command;
        $startedAt = MonotonicTime::nowNanoseconds();
        $deadline = MonotonicTime::addNanoseconds(
            $startedAt,
            MonotonicTime::secondsToNanoseconds($command->timeoutSeconds),
        );
        $termination = new ProcessTermination($this->policy, $deadline);
        $postExitDeadline = null;
        $outputAccepted = 0;
        $stdinBuffer = '';
        /** @var array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int}|null $terminalStatus */
        $terminalStatus = null;

        while (true) {
            $now = MonotonicTime::nowNanoseconds();
            $status = proc_get_status($child);
            $running = $status['running'];
            if (!$running) {
                $terminalStatus ??= $status;
            }

            $termination->observe($child, $command, $running, $now);
            if (!$running) {
                $postExitDeadline ??= MonotonicTime::addNanoseconds(
                    $now,
                    MonotonicTime::secondsToNanoseconds($this->policy->postExitDrainSeconds),
                );
                $this->closePipe($pipes, 0);
            }

            if ($this->finished($running, $pipes, $postExitDeadline, $now)) {
                break;
            }

            $this->primeInput($input, $stdinBuffer, $pipes);
            [$read, $write, $inputResourceId] = $this->selectSets($pipes, $input, $stdinBuffer);
            $this->waitForIo(
                $read,
                $write,
                $this->pollMicros($now, ...$termination->deadlines($postExitDeadline)),
            );
            $this->readInputResource($read, $inputResourceId, $input, $stdinBuffer);
            $this->writeInput($write, $pipes, $stdinBuffer, $input);
            $overflowed = $this->readOutputs($read, $pipes, $stdout, $stderr, $command->maxOutputBytes, $outputAccepted);
            $termination->observeOutputLimit(
                $child,
                $command,
                $overflowed,
                $running,
            );
        }

        $closeCode = $process->close();
        $exitCode = $this->exitCode($terminalStatus, $closeCode);
        $signal = $terminalStatus !== null && $terminalStatus['signaled']
            ? $terminalStatus['termsig']
            : null;

        return new ProcessResult(
            exitCode: $exitCode,
            stdout: $stdout->capture(),
            stderr: $stderr->capture(),
            stdoutBytes: $stdout->bytes(),
            stderrBytes: $stderr->bytes(),
            stdoutTruncated: $stdout->truncated(),
            stderrTruncated: $stderr->truncated(),
            terminationReason: $termination->reason(),
            terminationSignal: $signal === 0 ? null : $signal,
            durationSeconds: (MonotonicTime::nowNanoseconds() - $startedAt) / MonotonicTime::NANOSECONDS_PER_SECOND,
        );
    }

    /**
     * @param array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int}|null $status
     */
    private function exitCode(?array $status, mixed $closeCode): ?int
    {
        $statusCode = $status === null ? -1 : $status['exitcode'];
        if ($statusCode >= 0) {
            return $statusCode;
        }

        return is_int($closeCode) && $closeCode >= 0 ? $closeCode : null;
    }

    /** @param array<int, resource> $pipes */
    private function finished(bool $running, array $pipes, ?int $postExitDeadline, int $now): bool
    {
        if ($running) {
            return false;
        }
        $hasOutputPipes = isset($pipes[1]) || isset($pipes[2]);

        return !$hasOutputPipes || ($postExitDeadline !== null && $now >= $postExitDeadline);
    }

    /**
     * @param resource $inherit
     * @return resource|array{0: string, 1: string, 2?: string}
     */
    private function outputDescriptor(IoMode $mode, mixed $inherit): mixed
    {
        return match ($mode) {
            IoMode::CAPTURE, IoMode::STREAM => ['pipe', 'w'],
            IoMode::INHERIT => $inherit,
            IoMode::NULL => ['file', self::NULL_DEVICE, 'w'],
        };
    }

    private function pollMicros(int $now, ?int ...$deadlines): int
    {
        $micros = self::POLL_MICROS;
        foreach ($deadlines as $deadline) {
            if ($deadline === null || $deadline <= 0) {
                continue;
            }
            $until = max(0, $deadline - $now);
            $micros = min($micros, (int) ceil($until / 1_000));
        }

        return max(0, $micros);
    }

    /** @param array<int, resource> $pipes */
    private function primeInput(InputSource $input, string &$buffer, array &$pipes): void
    {
        if (!isset($pipes[0]) || $buffer !== '' || $input->isResource()) {
            return;
        }
        $chunk = $input->pull(self::IO_CHUNK_BYTES);
        if ($chunk === null) {
            $this->closePipe($pipes, 0);

            return;
        }
        $buffer = $chunk;
    }

    /** @param list<resource> $read */
    private function readInputResource(array $read, ?int $inputResourceId, InputSource $input, string &$buffer): void
    {
        if ($inputResourceId === null || $buffer !== '') {
            return;
        }
        foreach ($read as $resource) {
            if (get_resource_id($resource) !== $inputResourceId) {
                continue;
            }
            $chunk = $input->pull(self::IO_CHUNK_BYTES);
            if ($chunk !== null) {
                $buffer = $chunk;
            }

            return;
        }
    }

    /**
     * @param list<resource> $read
     * @param array<int, resource> $pipes
     */
    private function readOutputs(array $read, array &$pipes, OutputSink $stdout, OutputSink $stderr, int $limit, int &$accepted): bool
    {
        $overflowed = false;
        foreach ([1 => $stdout, 2 => $stderr] as $index => $sink) {
            if (!isset($pipes[$index]) || !in_array($pipes[$index], $read, true)) {
                continue;
            }
            $chunk = fread($pipes[$index], self::IO_CHUNK_BYTES);
            if ($chunk === false || ($chunk === '' && feof($pipes[$index]))) {
                $this->closePipe($pipes, $index);

                continue;
            }
            if ($chunk === '') {
                continue;
            }
            $remaining = max(0, $limit - $accepted);
            $allowed = min(strlen($chunk), $remaining);
            $sink->consume($chunk, $allowed);
            $accepted += $allowed;
            $overflowed = $overflowed || $allowed < strlen($chunk);
        }

        return $overflowed;
    }

    /**
     * @param array<int, resource> $pipes
     * @return array{0: list<resource>, 1: list<resource>, 2: ?int}
     */
    private function selectSets(array $pipes, InputSource $input, string $stdinBuffer): array
    {
        $read = [];
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                $read[] = $pipes[$index];
            }
        }
        $inputResourceId = null;
        if (isset($pipes[0]) && $stdinBuffer === '' && $input->isResource()) {
            $resource = $input->resource();
            if (is_resource($resource)) {
                $read[] = $resource;
                $inputResourceId = get_resource_id($resource);
            }
        }
        $write = [];
        if (isset($pipes[0]) && $stdinBuffer !== '' && is_resource($pipes[0])) {
            $write[] = $pipes[0];
        }

        return [$read, $write, $inputResourceId];
    }

    /** @return array{0: ProcessHandle, 1: array<int, resource>} */
    private function start(PreparedCommand $prepared): array
    {
        $command = $prepared->command;
        $descriptors = [
            0 => $command->stdin === null ? ['file', self::NULL_DEVICE, 'r'] : ['pipe', 'r'],
            1 => $this->outputDescriptor($command->stdoutMode, STDOUT),
            2 => $this->outputDescriptor($command->stderrMode, STDERR),
        ];
        $pipes = [];
        $process = proc_open(
            $prepared->argv,
            $descriptors,
            $pipes,
            $prepared->cwd,
            $prepared->environment,
            ['bypass_shell' => true],
        );

        if (!is_resource($process)) {
            throw new ProcessStartException('Unable to start child process.');
        }

        $handle = new ProcessHandle($process);

        try {
            foreach ($pipes as $pipe) {
                if (!stream_set_blocking($pipe, false)) {
                    throw new ProcessStartException('Unable to configure a child-process pipe as non-blocking.');
                }
            }
        } catch (Throwable $error) {
            $this->closePipes($pipes);
            $handle->abort();
            $handle->close(wait: false);

            throw $error;
        }

        return [$handle, $pipes];
    }

    private function validateConsumer(IoMode $mode, ?callable $consumer, string $stream): void
    {
        if ($mode === IoMode::STREAM && $consumer === null) {
            throw new ProcessStartException(sprintf('%s STREAM mode requires a consumer callback.', $stream));
        }
    }

    /**
     * @param list<resource> $read
     * @param list<resource> $write
     */
    private function waitForIo(array &$read, array &$write, int $micros): void
    {
        if ($read === [] && $write === []) {
            if ($micros > 0) {
                usleep($micros);
            }

            return;
        }
        $except = null;
        $seconds = intdiv($micros, 1_000_000);
        $remainingMicros = $micros % 1_000_000;
        $result = stream_select($read, $write, $except, $seconds, $remainingMicros);
        if ($result === false) {
            usleep(1_000);
            $read = [];
            $write = [];
        }
    }

    /**
     * @param list<resource> $write
     * @param array<int, resource> $pipes
     */
    private function writeInput(array $write, array &$pipes, string &$buffer, InputSource $input): void
    {
        if (!isset($pipes[0]) || !in_array($pipes[0], $write, true)) {
            if ($buffer === '' && $input->eof()) {
                $this->closePipe($pipes, 0);
            }

            return;
        }
        $written = fwrite($pipes[0], $buffer);
        if ($written === false) {
            $this->closePipe($pipes, 0);
            $buffer = '';

            return;
        }
        if ($written > 0) {
            $buffer = substr($buffer, $written);
        }
        if ($buffer === '' && $input->eof()) {
            $this->closePipe($pipes, 0);
        }
    }
}
