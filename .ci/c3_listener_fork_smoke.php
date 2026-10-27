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

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\TcpListener;

if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
    fwrite(STDERR, "Native listener fork smoke requires pcntl.\n");
    throw new RuntimeException('Native process extensions are required for this fixture.');
}

$listener = TcpListener::bind('127.0.0.1:0');
$child = pcntl_fork();
if ($child === -1) {
    throw new RuntimeException('Cannot fork prefork listener smoke.');
}
if ($child === 0) {
    $loop = new SelectLoop();
    $listener->start($loop, static function (): void {});
    $healthy = $listener->isAccepting();
    $listener->close();
    pcntl_exec(PHP_BINARY, ['-r', ($healthy && $loop->diagnostics()->readWatchers === 0) ? '' : 'throw new RuntimeException("Child fork assertion failed.");']);
    throw new RuntimeException('Unable to replace forked QA child process.');
}
pcntl_waitpid($child, $status);
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
    throw new RuntimeException('Prebound child cannot attach and retire its own event loop.');
}

$loop = new SelectLoop();
$listener->start($loop, static function (): void {});
if (!$listener->isAccepting()) {
    throw new RuntimeException('Original parent did not retain prefork listener ownership.');
}

$child = pcntl_fork();
if ($child === -1) {
    throw new RuntimeException('Cannot fork active-listener ownership smoke.');
}
if ($child === 0) {
    $rejected = 0;
    foreach ([
        static fn() => $listener->start(new SelectLoop(), static function (): void {}),
        static fn() => $listener->pauseAccepting(),
        static fn() => $listener->resumeAccepting(),
        static fn() => $listener->abortConnections(),
        static fn() => $listener->closeConnectionsGracefully(),
        static fn() => $listener->close(),
    ] as $operation) {
        try {
            $operation();
        } catch (LogicException) {
            ++$rejected;
        }
    }
    pcntl_exec(PHP_BINARY, ['-r', ($rejected === 6) ? '' : 'throw new RuntimeException("Child fork assertion failed.");']);
    throw new RuntimeException('Unable to replace forked QA child process.');
}
pcntl_waitpid($child, $status);
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
    throw new RuntimeException('Inherited active listener accepted cross-process lifecycle operations.');
}

if (!$listener->isAccepting()) {
    throw new RuntimeException('Forked child changed original owner state.');
}
$listener->close();
if ($loop->diagnostics()->readWatchers !== 0) {
    throw new RuntimeException('Listener close leaked parent loop watchers.');
}

fwrite(STDOUT, "C3 listener PID ownership: real prefork child, parent, inherited active operations PASS\n");
