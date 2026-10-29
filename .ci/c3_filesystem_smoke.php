<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Infocyph\\Runwire\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Filesystem\AsyncFileSystem;
use Infocyph\Runwire\Loop\SelectLoop;

$path = tempnam(sys_get_temp_dir(), 'runwire-c3-fs-');
if (!is_string($path)) {
    throw new RuntimeException('Unable to allocate filesystem fixture.');
}
$moved = $path . '.moved';
$loop = new SelectLoop();
$executor = new ProcessExecutor($loop, 1, 8);
$files = new AsyncFileSystem($executor);
$payload = str_repeat("A\0\xffZ", 16_384);
$ticks = 0;
$checks = [];
$pulse = $loop->repeat(0.003, static function () use (&$ticks): void {
    ++$ticks;
});

try {
    // A real subprocess blocks for 80 ms; the parent reactor must keep ticking.
    $executor->submit('sleep', ['milliseconds' => 80], static function (): void {});
    $files->write($path, $payload, static function (int $bytes, ?Throwable $error) use (
        $files, $path, $moved, $payload, &$checks,
    ): void {
        $checks['write'] = $bytes === strlen($payload) && $error === null;
        $files->read($path, strlen($payload), static function (string $data, ?Throwable $error) use (
            $files, $path, $moved, $payload, &$checks,
        ): void {
            $checks['read'] = $error === null && hash_equals(hash('sha256', $payload), hash('sha256', $data));
            $files->rename($path, $moved, static function (bool $ok, ?Throwable $error) use (
                $files, $moved, &$checks,
            ): void {
                $checks['rename'] = $ok && $error === null;
                $files->unlink($moved, static function (bool $removed, ?Throwable $error) use (&$checks): void {
                    $checks['unlink'] = $removed && $error === null;
                });
            });
        });
    });
    $executor->onDrained(static function () use ($loop, $pulse, $executor): void {
        $loop->cancel($pulse);
        $executor->close();
    });
    $loop->run();

    $diag = $executor->diagnostics();
    $loopDiagnostics = $loop->diagnostics();
    if (count($checks) !== 4 || in_array(false, $checks, true)
        || $ticks < 4 || $diag['workers'] !== 0 || $diag['queued'] !== 0
        || $loopDiagnostics->readWatchers !== 0 || $loopDiagnostics->writeWatchers !== 0
        || $loopDiagnostics->timersActive !== 0) {
        throw new RuntimeException('C3 portable filesystem IPC/cancellation/cleanup invariants failed: '
            . json_encode(['checks' => $checks, 'ticks' => $ticks, 'executor' => $diag]));
    }
    fwrite(STDOUT, "C3 off-reactor binary filesystem read/write/rename/unlink and heartbeat passed.\n");
} finally {
    $executor->close();
    if (is_file($path)) {
        unlink($path);
    }
    if (is_file($moved)) {
        unlink($moved);
    }
}
