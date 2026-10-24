<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Filesystem\ScopedFiles;
use Infocyph\Runwire\Coroutine\Filesystem\ScopedFileTransfer;
use Infocyph\Runwire\Coroutine\Network\AsyncConnection;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;

it('sends a 128 KiB binary file to a real socket with hash parity and worker cleanup', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'runwire-transfer-');
    expect($path)->toBeString();
    $payload = str_repeat("A\0\xfeB", 32_768);
    file_put_contents($path, $payload);
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$stream, $peer] = $pair;
    stream_set_blocking($peer, false);

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 4);
    $connection = new Connection($loop, $stream, new ConnectionLimits(
        sendLowWatermarkBytes: 4_096,
        sendHighWatermarkBytes: 8_192,
        maxSendBufferBytes: 16_384,
        maxWriteBytesPerTick: 4_096,
    ));
    $wire = '';
    $watcher = $loop->onReadable($peer, static function ($socket) use (&$wire): void {
        while (($chunk = fread($socket, 65_536)) !== false && $chunk !== '') {
            $wire .= $chunk;
        }
    });
    try {
        $runtime = new CoroutineRuntime($loop);
        $sent = $runtime->run(static function (CoroutineScope $scope) use ($connection, $executor, $path): int {
            $scope->registerShutdown(static function () use ($executor): void {
                $executor->close();
            });
            $files = new ScopedFiles(new AsyncFileSystem($executor), $scope);
            $network = new AsyncConnection($connection, $scope);
            $streamer = new ScopedFileTransfer($files, $network);
            $bytes = $streamer->send($path, 131_072, chunkBytes: 4_096);
            $network->close();

            return $bytes;
        });
        while (($chunk = fread($peer, 65_536)) !== false && $chunk !== '') {
            $wire .= $chunk;
        }
        expect($sent)->toBe(strlen($payload))
            ->and(hash('sha256', $wire))->toBe(hash('sha256', $payload))
            ->and($executor->diagnostics()['workers'])->toBe(0);
    } finally {
        $loop->cancel($watcher);
        $executor->close();
        $connection->abort();
        fclose($peer);
        unlink($path);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0);
});

it('receives real binary socket frames directly through scoped offset file writes', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'runwire-recv-');
    expect($path)->toBeString();
    $payload = str_repeat("a\0\xffB", 4_096);
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$stream, $peer] = $pair;
    stream_set_blocking($peer, false);
    fwrite($peer, $payload);
    stream_socket_shutdown($peer, STREAM_SHUT_WR);

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 4);
    $connection = new Connection($loop, $stream);
    try {
        $runtime = new CoroutineRuntime($loop);
        $bytes = $runtime->run(static function (CoroutineScope $scope) use ($connection, $executor, $path): int {
            $scope->registerShutdown(static function () use ($executor): void {
                $executor->close();
            });
            $files = new ScopedFiles(new AsyncFileSystem($executor), $scope);
            $network = new AsyncConnection($connection, $scope);

            return (new ScopedFileTransfer($files, $network))->receive($path, 20_000, chunkBytes: 2_048);
        });
        expect($bytes)->toBe(strlen($payload))
            ->and(hash_file('sha256', $path))->toBe(hash('sha256', $payload))
            ->and($executor->diagnostics()['workers'])->toBe(0);
    } finally {
        $executor->close();
        $connection->abort();
        fclose($peer);
        unlink($path);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0);
});
