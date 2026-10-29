<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Infocyph\\Runwire\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Supervisor\WorkerContext;

$loop = new SelectLoop();
$executor = new ProcessExecutor($loop, 1, 2);
$dialer = new Dialer($loop);
$happy = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
[$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$pid = pcntl_fork();
if ($pid < 0) {
    throw new RuntimeException('Unable to fork outbound resource ownership smoke.');
}
if ($pid === 0) {
    fclose($reader);
    $rejected = 0;
    foreach ([
        static fn() => $dialer->cancel(42),
        static fn() => $dialer->close(),
        static fn() => $happy->pendingCount(),
        static fn() => $happy->close(),
    ] as $operation) {
        try {
            $operation();
        } catch (LogicException) {
            ++$rejected;
        }
    }
    fwrite($writer, (string) $rejected);
    fclose($writer);
    pcntl_exec(PHP_BINARY, ['-r', ($rejected === 4) ? '' : 'throw new RuntimeException("Child fork assertion failed.");']);
    throw new RuntimeException('Unable to replace forked QA child process.');
}
fclose($writer);
$childResult = stream_get_contents($reader);
fclose($reader);
pcntl_waitpid($pid, $status);
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0 || $childResult !== '4') {
    throw new RuntimeException('Inherited dial ownership was not rejected by forked child.');
}

$server = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
if (!is_resource($server)) {
    throw new RuntimeException('Unable to bind outgoing native pending-dial fixture.');
}
$address = stream_socket_get_name($server, false);
$context = new WorkerContext('dialer-native', 0, 1, posix_getpid(), posix_getpid());
$settled = [];
try {
    $context->ownHappyEyeballsDialer($happy);
    $context->ownDialer($dialer);
    $dialer->connect('tcp://' . $address, static function ($connection, ?Throwable $error) use (&$settled): void {
        $settled[] = [$connection, $error];
    });
    $context->requestStop();
    $context->close();
    $diag = $loop->diagnostics();
    if (count($settled) !== 1 || $settled[0][0] !== null || !$settled[0][1] instanceof Throwable
        || $dialer->pendingCount() !== 0 || $happy->pendingCount() !== 0
        || $diag->readWatchers !== 0 || $diag->writeWatchers !== 0 || $diag->timersActive !== 0) {
        throw new RuntimeException('Owned pending dials did not cancel exactly once and retire watchers.');
    }
    fwrite(STDOUT, "Native fork-safe outbound dial and worker-owned pending cancellation passed.\n");
} finally {
    fclose($server);
    $context->close();
    $happy->close();
    $dialer->close();
    $executor->close();
}
