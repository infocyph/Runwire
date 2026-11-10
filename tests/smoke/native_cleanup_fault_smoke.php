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
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\WorkerContext;

$actual = new SelectLoop();
$loop = new class($actual) implements LoopInterface {
    public bool $injectCancelFailure = false;

    public function __construct(private readonly SelectLoop $actual) {}

    /**
     * @throws \RuntimeException
     */
    #[\Override]
    public function cancel(int $id): bool
    {
        if ($this->injectCancelFailure) {
            $this->injectCancelFailure = false;
            throw new RuntimeException('Injected periodic loop cancel fault');
        }
        return $this->actual->cancel($id);
    }

    /**
     * @throws \OverflowException
     */
    #[\Override]
    public function defer(callable $callback): int { return $this->actual->defer($callback); }
    /**
     * @throws \OverflowException
     * @throws \InvalidArgumentException
     */
    #[\Override]
    public function delay(float $seconds, callable $callback): int { return $this->actual->delay($seconds, $callback); }
    #[\Override]
    public function now(): float { return $this->actual->now(); }
    /**
     * @throws \InvalidArgumentException
     * @throws \OverflowException
     */
    #[\Override]
    public function onReadable(mixed $stream, callable $callback): int { return $this->actual->onReadable($stream, $callback); }
    /**
     * @throws \InvalidArgumentException
     * @throws \OverflowException
     */
    #[\Override]
    public function onWritable(mixed $stream, callable $callback): int { return $this->actual->onWritable($stream, $callback); }
    /**
     * @throws \OverflowException
     * @throws \InvalidArgumentException
     */
    #[\Override]
    public function repeat(float $interval, callable $callback): int { return $this->actual->repeat($interval, $callback); }
    /**
     * @throws \LogicException
     * @throws \RuntimeException
     * @throws \Throwable
     */
    #[\Override]
    public function run(): void { $this->actual->run(); }
    #[\Override]
    public function stop(): void { $this->actual->stop(); }
};

$context = new WorkerContext('cleanup-fault', 0, 1, posix_getpid(), posix_getpid());
$executor = new ProcessExecutor($loop, 1, 2);
$context->attachLoop($loop);
$context->every('first', 20, static function (): void {});
$context->every('second', 20, static function (): void {});
$context->ownExecutor($executor);
$loop->injectCancelFailure = true;

$failureObserved = false;
try {
    $context->requestStop();
} catch (RuntimeException $error) {
    $failureObserved = $error->getMessage() === 'Injected periodic loop cancel fault';
}

if (!$failureObserved || !$context->stopping() || $context->acceptingBackgroundWork()) {
    throw new RuntimeException('Failed timer cancellation did not enter orderly worker shutdown.');
}

$read = [$context->stopStream()];
$write = [];
$except = [];
if (stream_select($read, $write, $except, 0, 100_000) !== 1) {
    throw new RuntimeException('A failed periodic timer cancellation blocked worker stop wake.');
}
$context->consumeStopWake();
$context->close();
$context->close();

$diagnostics = $actual->diagnostics();
if ($diagnostics->timersActive !== 0 || $diagnostics->readWatchers !== 0 || $diagnostics->writeWatchers !== 0) {
    throw new RuntimeException('Worker-owned resources remained after injected timer-cancel fault.');
}

fwrite(STDOUT, "Native worker stop fault isolation and retryable periodic timer cleanup PASS\n");
