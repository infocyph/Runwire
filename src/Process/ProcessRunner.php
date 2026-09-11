<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process;

use Closure;
use Infocyph\Runwire\Exception\ProcessException;
use Infocyph\Runwire\Exception\ProcessStartException;
use Infocyph\Runwire\Process\Internal\CommandValidator;
use Infocyph\Runwire\Process\Internal\InputSource;
use Infocyph\Runwire\Process\Internal\OutputSink;
use Infocyph\Runwire\Process\Internal\PreparedCommand;
use Throwable;

final class ProcessRunner
{
    private const int NANOS_PER_SECOND = 1_000_000_000;
    private const int IO_CHUNK_BYTES = 65_536;
    private const int POLL_MICROS = 50_000;

    private readonly ProcessPolicy $policy;
    private readonly CommandValidator $validator;

    public function __construct(?ProcessPolicy $policy = null)
    {
        $this->policy = $policy ?? new ProcessPolicy();
        $this->validator = new CommandValidator($this->policy);
    }

    public function run(Command $command, ?callable $stdoutConsumer = null, ?callable $stderrConsumer = null): ProcessResult
    {
        $this->ensureProcessFunctions();
        $prepared = $this->validator->validate($command);
        $this->validateConsumer($command->stdoutMode, $stdoutConsumer, 'stdout');
        $this->validateConsumer($command->stderrMode, $stderrConsumer, 'stderr');

        [$process, $pipes] = $this->start($prepared);
        $input = new InputSource($command->stdin, $this->policy->maxStdinBytes);
        $stdout = new OutputSink($command->stdoutMode, self::closure($stdoutConsumer));
        $stderr = new OutputSink($command->stderrMode, self::closure($stderrConsumer));

        try {
            return $this->execute($prepared, $process, $pipes, $input, $stdout, $stderr);
        } catch (Throwable $exception) {
            $this->abort($process);
            throw $exception;
        } finally {
            $input->close();
            $this->closePipes($pipes);
            if (is_resource($process)) {
                @proc_close($process);
            }
        }
    }

    /** @return array{0: resource, 1: array<int, resource>} */
    private function start(PreparedCommand $prepared): array
    {
        $command = $prepared->command;
        $descriptors = [
            0 => $command->stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'],
            1 => $this->outputDescriptor($command->stdoutMode, STDOUT),
            2 => $this->outputDescriptor($command->stderrMode, STDERR),
        ];
        $pipes = [];
        $process = @proc_open(
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

        foreach ($pipes as $pipe) {
            @stream_set_blocking($pipe, false);
        }

        return [$process, $pipes];
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
            IoMode::NULL => ['file', '/dev/null', 'w'],
        };
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function execute(PreparedCommand $prepared, mixed &$process, array &$pipes, InputSource $input, OutputSink $stdout, OutputSink $stderr): ProcessResult
    {
        $command = $prepared->command;
        $startedAt = hrtime(true);
        $deadline = $startedAt + $this->secondsToNanos($command->timeoutSeconds);
        $terminationDeadline = null;
        $postExitDeadline = null;
        $reason = TerminationReason::EXITED;
        $killSent = false;
        $outputAccepted = 0;
        $stdinBuffer = '';
        $terminalStatus = null;

        while (true) {
            $now = hrtime(true);
            $status = @proc_get_status($process);
            if (!is_array($status)) {
                throw new ProcessException('Unable to inspect child process status.');
            }
            if (!$status['running']) {
                $terminalStatus ??= $status;
            }

            if ($status['running']) {
                [$reason, $terminationDeadline] = $this->enforceDeadline($process, $reason, $terminationDeadline, $deadline, $command, $now);
                $killSent = $this->enforceKill($process, $terminationDeadline, $killSent, $now);
            } else {
                $postExitDeadline ??= $now + $this->secondsToNanos($this->policy->postExitDrainSeconds);
                $this->closePipe($pipes, 0);
            }

            if ($this->finished($status['running'], $pipes, $postExitDeadline, $now)) {
                break;
            }

            $this->primeInput($input, $stdinBuffer, $pipes);
            [$read, $write, $inputResourceId] = $this->selectSets($pipes, $input, $stdinBuffer);
            $this->waitForIo($read, $write, $this->pollMicros($now, $deadline, $terminationDeadline, $postExitDeadline));
            $this->readInputResource($read, $inputResourceId, $input, $stdinBuffer);
            $this->writeInput($write, $pipes, $stdinBuffer, $input);
            $overflowed = $this->readOutputs($read, $pipes, $stdout, $stderr, $command->maxOutputBytes, $outputAccepted);

            if ($overflowed && $command->overflowPolicy === OutputOverflowPolicy::TERMINATE && $terminationDeadline === null && $status['running']) {
                $reason = TerminationReason::OUTPUT_LIMIT;
                @proc_terminate($process, SIGTERM);
                $terminationDeadline = hrtime(true) + $this->secondsToNanos($command->terminationGraceSeconds);
            }
        }

        $closeCode = @proc_close($process);
        $process = null;
        $exitCode = $this->exitCode($terminalStatus, $closeCode);
        $signal = is_array($terminalStatus) && ($terminalStatus['signaled'] ?? false)
            ? (int) ($terminalStatus['termsig'] ?? 0)
            : null;

        return new ProcessResult(
            exitCode: $exitCode,
            stdout: $stdout->capture(),
            stderr: $stderr->capture(),
            stdoutBytes: $stdout->bytes(),
            stderrBytes: $stderr->bytes(),
            stdoutTruncated: $stdout->truncated(),
            stderrTruncated: $stderr->truncated(),
            terminationReason: $reason,
            terminationSignal: $signal === 0 ? null : $signal,
            durationSeconds: (hrtime(true) - $startedAt) / self::NANOS_PER_SECOND,
        );
    }

    /** @param resource $process @return array{TerminationReason, ?int} */
    private function enforceDeadline(mixed $process, TerminationReason $reason, ?int $terminationDeadline, int $deadline, Command $command, int $now): array
    {
        if ($terminationDeadline !== null || $now < $deadline) {
            return [$reason, $terminationDeadline];
        }
        @proc_terminate($process, SIGTERM);
        return [TerminationReason::TIMEOUT, $now + $this->secondsToNanos($command->terminationGraceSeconds)];
    }

    /** @param resource $process */
    private function enforceKill(mixed $process, ?int $terminationDeadline, bool $killSent, int $now): bool
    {
        if ($killSent || $terminationDeadline === null || $now < $terminationDeadline) {
            return $killSent;
        }
        @proc_terminate($process, SIGKILL);
        return true;
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

    /** @param array<int, resource> $pipes @return array{list<resource>, list<resource>, ?int} */
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

    /** @param list<resource> $read @param list<resource> $write */
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
        $result = @stream_select($read, $write, $except, $seconds, $remainingMicros);
        if ($result === false) {
            usleep(1_000);
            $read = [];
            $write = [];
        }
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

    /** @param list<resource> $write @param array<int, resource> $pipes */
    private function writeInput(array $write, array &$pipes, string &$buffer, InputSource $input): void
    {
        if (!isset($pipes[0]) || !in_array($pipes[0], $write, true)) {
            if ($buffer === '' && $input->eof()) {
                $this->closePipe($pipes, 0);
            }
            return;
        }
        $written = @fwrite($pipes[0], $buffer);
        if ($written === false) {
            $this->closePipe($pipes, 0);
            $buffer = '';
            return;
        }
        if ($written > 0) {
            $buffer = (string) substr($buffer, $written);
        }
        if ($buffer === '' && $input->eof()) {
            $this->closePipe($pipes, 0);
        }
    }

    /** @param list<resource> $read @param array<int, resource> $pipes */
    private function readOutputs(array $read, array &$pipes, OutputSink $stdout, OutputSink $stderr, int $limit, int &$accepted): bool
    {
        $overflowed = false;
        foreach ([1 => $stdout, 2 => $stderr] as $index => $sink) {
            if (!isset($pipes[$index]) || !in_array($pipes[$index], $read, true)) {
                continue;
            }
            $chunk = @fread($pipes[$index], self::IO_CHUNK_BYTES);
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

    /** @param array<string, mixed>|null $status */
    private function exitCode(?array $status, mixed $closeCode): ?int
    {
        $statusCode = is_array($status) ? (int) ($status['exitcode'] ?? -1) : -1;
        if ($statusCode >= 0) {
            return $statusCode;
        }
        return is_int($closeCode) && $closeCode >= 0 ? $closeCode : null;
    }

    private function ensureProcessFunctions(): void
    {
        foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $function) {
            if (!function_exists($function)) {
                throw new ProcessStartException(sprintf('Required process function "%s" is unavailable or disabled.', $function));
            }
        }
    }

    private function validateConsumer(IoMode $mode, ?callable $consumer, string $stream): void
    {
        if ($mode === IoMode::STREAM && $consumer === null) {
            throw new ProcessStartException(sprintf('%s STREAM mode requires a consumer callback.', $stream));
        }
    }

    /** @param array<int, resource> $pipes */
    private function closePipe(array &$pipes, int $index): void
    {
        if (isset($pipes[$index]) && is_resource($pipes[$index])) {
            @fclose($pipes[$index]);
        }
        unset($pipes[$index]);
    }

    /** @param array<int, resource> $pipes */
    private function closePipes(array &$pipes): void
    {
        foreach (array_keys($pipes) as $index) {
            $this->closePipe($pipes, (int) $index);
        }
    }

    /** @param resource $process */
    private function abort(mixed $process): void
    {
        if (!is_resource($process)) {
            return;
        }
        $status = @proc_get_status($process);
        if (is_array($status) && ($status['running'] ?? false)) {
            @proc_terminate($process, SIGKILL);
        }
    }

    private function secondsToNanos(float $seconds): int
    {
        return (int) round($seconds * self::NANOS_PER_SECOND);
    }

    private static function closure(?callable $consumer): ?Closure
    {
        return $consumer === null ? null : Closure::fromCallable($consumer);
    }
}
