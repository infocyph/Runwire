<?php

declare(strict_types=1);

use Infocyph\Runwire\Process\Command;
use Infocyph\Runwire\Process\Enum\TerminationReason;
use Infocyph\Runwire\Process\Internal\ProcessHandle;
use Infocyph\Runwire\Process\ProcessRunner;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$fail = static function (string $message): never {
    throw new RuntimeException($message);
};

if (function_exists('pcntl_fork') || defined('SIGTERM') || defined('SIGKILL')) {
    $fail('Portable ProcessRunner acceptance requires PCNTL and signal constants to be unavailable.');
}

$runner = new ProcessRunner();
$normal = $runner->run(Command::executable(PHP_BINARY, ['-r', 'echo "ok";']));
if (!$normal->successful() || $normal->stdout !== 'ok') {
    $fail('Normal child execution failed without PCNTL.');
}

$timeout = $runner->run(
    Command::executable(PHP_BINARY, ['-r', 'usleep(1000000);'])
        ->timeout(0.05)
        ->terminationGrace(0.01),
);
if (!$timeout->timedOut()) {
    $fail('Timeout termination failed without PCNTL.');
}

$outputLimit = $runner->run(
    Command::executable(PHP_BINARY, ['-r', 'while(true){echo str_repeat("x",4096);}'])
        ->maxOutputBytes(10_000)
        ->timeout(2.0)
        ->terminationGrace(0.01),
);
if ($outputLimit->terminationReason !== TerminationReason::OUTPUT_LIMIT) {
    $fail('Output-limit termination failed without PCNTL.');
}

$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', '/dev/null', 'w'],
    2 => ['file', '/dev/null', 'w'],
];
$pipes = [];
$process = proc_open([PHP_BINARY, '-r', 'usleep(1000000);'], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
if (!is_resource($process)) {
    $fail('Unable to create explicit-abort child.');
}
$handle = new ProcessHandle($process);
$handle->abort();
$handle->close();

fwrite(STDOUT, "portable-process-runner-ok\n");
