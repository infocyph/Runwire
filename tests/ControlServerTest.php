<?php

declare(strict_types=1);

use Infocyph\Runwire\Control\ControlOptions;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;

it('serves bounded runtime control over a protected unix socket', function (): void {
    $socket = sys_get_temp_dir() . '/runwire-control-test-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sock';
    $resultFile = tempnam(sys_get_temp_dir(), 'runwire-control-result-');
    if ($resultFile === false) {
        throw new RuntimeException('Unable to allocate control result file.');
    }
    @unlink($socket);

    $clientPid = pcntl_fork();
    if ($clientPid === -1) {
        throw new RuntimeException('Unable to fork control client.');
    }

    if ($clientPid === 0) {
        $request = static function (array $payload) use ($socket): array {
            $deadline = microtime(true) + 3.0;
            $stream = false;
            do {
                $errno = 0;
                $error = '';
                $stream = @stream_socket_client('unix://' . $socket, $errno, $error, 0.2);
                if (is_resource($stream)) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);

            if (!is_resource($stream)) {
                throw new RuntimeException('Unable to connect to control socket.');
            }

            stream_set_timeout($stream, 2);
            $wire = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            if (fwrite($stream, $wire) !== strlen($wire)) {
                throw new RuntimeException('Short control request write.');
            }
            $line = fgets($stream);
            fclose($stream);
            if (!is_string($line)) {
                throw new RuntimeException('Missing control response.');
            }
            $decoded = json_decode(trim($line), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new RuntimeException('Invalid control response.');
            }

            return $decoded;
        };

        try {
            $deadline = microtime(true) + 3.0;
            while (!file_exists($socket) && microtime(true) < $deadline) {
                usleep(5_000);
            }
            if (!file_exists($socket)) {
                throw new RuntimeException('Control socket was not created.');
            }

            $oversized = @stream_socket_client('unix://' . $socket, $errno, $error, 1.0);
            if (!is_resource($oversized)) {
                throw new RuntimeException('Unable to connect oversized-request client.');
            }
            fwrite($oversized, str_repeat('x', 600) . "\n");
            stream_set_timeout($oversized, 1);
            $oversizedReply = fgets($oversized);
            fclose($oversized);
            if ($oversizedReply !== false) {
                throw new RuntimeException('Oversized request unexpectedly received a response.');
            }

            $mode = fileperms($socket);
            $mode = is_int($mode) ? ($mode & 0777) : -1;
            $status = $request(['version' => 1, 'action' => 'status']);
            $runtimeId = $status['runtime_id'] ?? null;
            if (!is_string($runtimeId)) {
                throw new RuntimeException('Status did not return a runtime ID.');
            }
            $stale = $request([
                'version' => 1,
                'action' => 'reload',
                'runtime_id' => str_repeat('0', strlen($runtimeId)),
            ]);
            $stop = $request([
                'version' => 1,
                'action' => 'stop',
                'runtime_id' => $runtimeId,
                'force' => false,
            ]);

            file_put_contents($resultFile, json_encode(
                compact('mode', 'status', 'stale', 'stop'),
                JSON_THROW_ON_ERROR,
            ));
            exit(0);
        } catch (Throwable $error) {
            file_put_contents($resultFile, json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR));
            exit(1);
        }
    }

    try {
        $supervisor = (new Supervisor())
            ->control(new ControlOptions($socket, permissions: 0600, maxRequestBytes: 512));
        $supervisor->group(WorkerGroup::callbacks(
            name: 'workers',
            count: 1,
            factory: static function (WorkerContext $context): void {
                $context->ready();
                $stop = $context->stopStream();
                while (!$context->stopping()) {
                    $read = [$stop];
                    $write = [];
                    $except = [];
                    @stream_select($read, $write, $except, 1, 0);
                    $context->consumeStopWake();
                }
            },
            automaticReady: false,
            readyTimeoutSeconds: 0.5,
            shutdownTimeoutSeconds: 0.3,
        ));
        $supervisor->run();

        $raw = file_get_contents($resultFile);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Control client did not write a result.');
        }
        $result = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new RuntimeException('Invalid control result.');
        }

        expect($result)->not->toHaveKey('error')
            ->and($result['mode'])->toBe(0600)
            ->and($result['status']['ok'])->toBeTrue()
            ->and($result['status']['runtime_id'])->toHaveLength(32)
            ->and($result['stale']['ok'])->toBeFalse()
            ->and($result['stale']['error']['code'])->toBe('runtime_mismatch')
            ->and($result['stop']['data']['accepted'])->toBeTrue()
            ->and(file_exists($socket))->toBeFalse()
            ->and($supervisor->status()->workers)->toBe([]);
    } finally {
        @unlink($socket);
        @unlink($resultFile);
    }
});

it('rejects unsafe control options', function (): void {
    expect(fn () => new ControlOptions('relative.sock'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ControlOptions('/tmp/runwire.sock', permissions: 0666))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ControlOptions('/tmp/runwire.sock', maxRequestBytes: 128))->toThrow(InvalidArgumentException::class);
});
