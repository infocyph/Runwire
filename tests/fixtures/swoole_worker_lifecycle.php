<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\RuntimeApplicationFactoryInterface;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\SwooleOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
$trace = $argv[2] ?? '';
$api = $argv[3] ?? 'factory';
if ($port < 1 || $port > 65_535 || $trace === '' || !in_array($api, ['factory', 'callback'], true)) {
    throw new InvalidArgumentException('Expected a port, trace path and factory/callback API.');
}
$record = static function (string $event, RuntimeContext $context) use ($trace): void {
    $line = json_encode(['event' => $event, 'pid' => getmypid(), 'runtime_pid' => $context->pid], JSON_THROW_ON_ERROR);
    if (file_put_contents($trace, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Unable to record Swoole lifecycle evidence.');
    }
};
$hooks = new ApplicationLifecycleHooks(
    boot: static function (RuntimeContext $context) use ($record): void { $record('boot', $context); },
    drain: static function (RuntimeContext $context) use ($record): void { $record('drain', $context); },
    shutdown: static function (RuntimeContext $context) use ($record): void { $record('shutdown', $context); },
);
$runtime = Runtime::create(new RuntimeOptions(
    driver: RuntimeDriver::SWOOLE,
    swoole: new SwooleOptions(host: '127.0.0.1', port: $port, workerCount: 2),
    applicationLifecycle: $hooks,
));
$handler = static function (HttpRequest $request, ResponseWriterInterface $writer) use ($runtime): void {
    $context = $request->context->runtime();
    if ($context->pid !== getmypid()) {
        throw new LogicException('Swoole request inherited its parent runtime context.');
    }
    $writer->start(200, Headers::fromArray(['content-type' => 'application/json']));
    $writer->end(json_encode([
        'pid' => getmypid(),
        'runtime_pid' => $context->pid,
        'worker_slot' => $context->workerSlot,
        'request_id' => $request->context->requestId,
    ], JSON_THROW_ON_ERROR));
    if ($request->target === '/stop') {
        $runtime->stop();
    }
};

if ($api === 'callback') {
    $runtime->serve($handler);
} else {
    $runtime->serveApplication(new class($handler, $hooks, $record) implements RuntimeApplicationFactoryInterface {
        /** @param Closure(string, RuntimeContext): void $record */
        public function __construct(
            private readonly Closure $handler,
            private readonly ApplicationLifecycleHooks $hooks,
            private readonly Closure $record,
        ) {}

        public function create(RuntimeContext $context): RuntimeApplicationInterface
        {
            ($this->record)('create', $context);

            return new RuntimeApplication(
                function (HttpRequest $request, ResponseWriterInterface $writer) use ($context): void {
                    if ($request->context->runtime() !== $context) {
                        throw new LogicException('Swoole request changed the factory context instance.');
                    }
                    ($this->handler)($request, $writer);
                },
                runtimeContext: $context,
                lifecycle: $this->hooks,
            );
        }
    });
}
