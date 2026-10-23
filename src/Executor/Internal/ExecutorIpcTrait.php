<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Executor\Internal;

use RuntimeException;
use Throwable;

/** @internal Nonblocking subprocess pipe and worker lifecycle mechanics. */
trait ExecutorIpcTrait
{

    /** @return array{mixed, ?Throwable} */
    private function decodeResponse(int $jobId, string $payload): array
    {
        $response = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($response) || ($response['id'] ?? null) !== $jobId || !is_bool($response['ok'] ?? null)) {
            throw new RuntimeException('Executor returned an invalid response.');
        }
        if (!$response['ok']) {
            $message = $response['error'] ?? null;

            return [null, new RuntimeException(is_string($message) ? $message : 'Executor operation failed.')];
        }

        $value = $response['value'] ?? null;
        if (str_contains($this->jobs[$jobId]['packet'], '"op":"read"')) {
            $value = is_string($value) ? base64_decode($value, true) : false;
            if ($value === false) {
                throw new RuntimeException('Executor returned invalid file data.');
            }
        }

        return [$value, null];
    }

    /** @param resource $pipe */
    private function readPipe(mixed $pipe): string|false
    {
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            return fread($pipe, self::IO_CHUNK_BYTES);
        } finally {
            restore_error_handler();
        }
    }

    private function readWorker(int $id): void
    {
        if (!isset($this->workers[$id])) {
            return;
        }
        $chunk = $this->readPipe($this->workers[$id]['output'], self::IO_CHUNK_BYTES);
        if ($chunk === false || ($chunk === '' && feof($this->workers[$id]['output']))) {
            $this->retire($id, new RuntimeException('Executor subprocess exited or closed its output.'));

            return;
        }
        if ($chunk === '') {
            return;
        }
        $this->workers[$id]['outputBuffer'] .= $chunk;
        $buffer = $this->workers[$id]['outputBuffer'];
        if (strlen($buffer) > self::MAX_PACKET_BYTES) {
            $this->retire($id, new RuntimeException('Executor subprocess exceeded response limits.'));

            return;
        }
        $newline = strpos($buffer, "\n");
        if ($newline === false) {
            return;
        }
        $jobId = $this->workers[$id]['job'];
        if ($jobId === null || $newline !== strlen($buffer) - 1) {
            $this->retire($id, new RuntimeException('Unexpected executor response frame.'));

            return;
        }

        try {
            [$value, $error] = $this->decodeResponse($jobId, substr($buffer, 0, $newline));
        } catch (Throwable $error) {
            $this->retire($id, $error);

            return;
        }

        $this->workers[$id]['outputBuffer'] = '';
        $this->workers[$id]['job'] = null;
        $this->settle($jobId, $value, $error);
    }

    private function retire(int $id, Throwable $reason): void
    {
        $worker = $this->workers[$id] ?? null;
        if ($worker === null) {
            return;
        }
        unset($this->workers[$id]);
        $this->loop->cancel($worker['readWatcher']);
        if ($worker['writeWatcher'] !== null) {
            $this->loop->cancel($worker['writeWatcher']);
        }
        fclose($worker['input']);
        fclose($worker['output']);
        $status = proc_get_status($worker['process']);
        if ($status['running']) {
            proc_terminate($worker['process'], 9);
        }
        proc_close($worker['process']);
        if ($worker['job'] !== null) {
            $this->settle($worker['job'], null, $reason);
        }
        $this->schedulePump();
    }

    private function startWorker(): int
    {
        $script = dirname(__DIR__, 3) . '/tools/executor-worker.php';
        $pipes = [];
        $nullDevice = '/dev/null';
        $process = proc_open(
            [$this->phpBinary, '-d', 'display_errors=stderr', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $nullDevice, 'a']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start executor subprocess.');
        }
        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);

        $id = $this->nextWorkerId++;

        try {
            $readWatcher = $this->loop->onReadable($pipes[1], function () use ($id): void {
                $this->readWorker($id);
            });
        } catch (Throwable $error) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            proc_terminate($process, 9);
            proc_close($process);

            throw $error;
        }
        $this->workers[$id] = [
            'process' => $process,
            'input' => $pipes[0],
            'output' => $pipes[1],
            'readWatcher' => $readWatcher,
            'writeWatcher' => null,
            'inputBuffer' => '',
            'outputBuffer' => '',
            'job' => null,
        ];

        return $id;
    }

    /** @param resource $pipe */
    private function writePipe(mixed $pipe, string $payload): int|false
    {
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            return fwrite($pipe, $payload);
        } finally {
            restore_error_handler();
        }
    }

    private function writeWorker(int $id): void
    {
        if (!isset($this->workers[$id])) {
            return;
        }
        $worker = &$this->workers[$id];
        $sent = $this->writePipe($worker['input'], substr($worker['inputBuffer'], 0, self::IO_CHUNK_BYTES));
        if ($sent === false || ($sent === 0 && feof($worker['input']))) {
            unset($worker);
            $this->retire($id, new RuntimeException('Executor subprocess input closed.'));

            return;
        }
        $worker['inputBuffer'] = substr($worker['inputBuffer'], $sent);
        if ($worker['inputBuffer'] === '' && $worker['writeWatcher'] !== null) {
            $this->loop->cancel($worker['writeWatcher']);
            $worker['writeWatcher'] = null;
        }
        unset($worker);
    }

}
