<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Driver;

use Closure;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\FrankenPhpOptions;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\Enum\FrankenPhpMode;
use Infocyph\Runwire\Runtime\Host\HostDriverInterface;
use Infocyph\Runwire\Runtime\Host\HostRequestFactory;
use Infocyph\Runwire\Runtime\Host\NativePhpResponseWriterFactory;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\Internal\WorkerRecycleState;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

final readonly class FrankenPhpDriver implements HostDriverInterface
{
    /** @var Closure(): HttpRequest */
    private Closure $requestFactory;

    /** @var Closure(callable): bool|null */
    private ?Closure $workerRequestHandler;

    /** @var Closure(string): ResponseWriterInterface */
    private Closure $writerFactory;

    /**
     * @param callable(): HttpRequest|null $requestFactory
     * @param callable(string): ResponseWriterInterface|null $writerFactory
     * @param callable(callable): bool|null $workerRequestHandler
     */
    public function __construct(
        private FrankenPhpOptions $options,
        ?callable $requestFactory = null,
        ?callable $writerFactory = null,
        ?callable $workerRequestHandler = null,
        private WorkerRecyclePolicy $recyclePolicy = new WorkerRecyclePolicy(),
    ) {
        $hostRequestFactory = new HostRequestFactory();
        $nativeWriterFactory = new NativePhpResponseWriterFactory();
        $this->requestFactory = $requestFactory === null
            ? static fn(): HttpRequest => $hostRequestFactory->fromGlobals($options->maxRequestBodyBytes)
            : Closure::fromCallable($requestFactory);
        $this->workerRequestHandler = $workerRequestHandler === null
            ? self::nativeWorkerRequestHandler()
            : Closure::fromCallable($workerRequestHandler);
        $this->writerFactory = $writerFactory === null
            ? static fn(string $method): ResponseWriterInterface => $nativeWriterFactory->create($method, $options->maxResponseBytes)
            : Closure::fromCallable($writerFactory);
    }

    public function run(RuntimeApplication $application): void
    {
        try {
            if ($this->mode() === FrankenPhpMode::WORKER) {
                $this->runWorker($application);

                return;
            }

            $this->handleCurrentRequest($application);
        } finally {
            $application->shutdown();
        }
    }

    public function stop(): void {}

    /** @return Closure(callable): bool|null */
    private static function nativeWorkerRequestHandler(): ?Closure
    {
        if (!function_exists('frankenphp_handle_request')) {
            return null;
        }

        /** @var Closure(callable): bool $handler */
        $handler = \frankenphp_handle_request(...);

        return $handler;
    }

    private function handleCurrentRequest(RuntimeApplication $application): void
    {
        $request = ($this->requestFactory)();
        $application->handle($request, ($this->writerFactory)($request->method));
    }

    private function mode(): FrankenPhpMode
    {
        if ($this->options->mode !== FrankenPhpMode::AUTO) {
            return $this->options->mode;
        }

        $config = getenv('FRANKENPHP_CONFIG');

        return is_string($config) && preg_match('/(?:^|\s)worker(?:\s|$)/i', $config) === 1
            ? FrankenPhpMode::WORKER
            : FrankenPhpMode::CLASSIC;
    }

    private function runWorker(RuntimeApplication $application): void
    {
        if ($this->workerRequestHandler === null) {
            throw new RuntimeUnavailableException('FrankenPHP worker mode requires frankenphp_handle_request().');
        }

        $recycle = new WorkerRecycleState($this->recyclePolicy);
        while (true) {
            $recycleRequested = false;
            $keepRunning = ($this->workerRequestHandler)(function () use ($application, $recycle, &$recycleRequested): void {
                try {
                    $this->handleCurrentRequest($application);
                } finally {
                    gc_collect_cycles();
                    $recycleRequested = $recycle->recordRequestCompleted();
                }
            });
            if ($recycleRequested || !$keepRunning) {
                return;
            }
        }
    }
}
