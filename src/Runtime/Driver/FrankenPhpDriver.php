<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Driver;

use Closure;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\FrankenPhpMode;
use Infocyph\Runwire\FrankenPhpOptions;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\Host\HostDriverInterface;
use Infocyph\Runwire\Runtime\Host\HostRequestFactory;
use Infocyph\Runwire\Runtime\Host\NativePhpResponseWriterFactory;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;

final readonly class FrankenPhpDriver implements HostDriverInterface
{
    /** @var Closure(): HttpRequest */
    private Closure $requestFactory;

    /** @var Closure(callable(): void): bool|null */
    private ?Closure $workerRequestHandler;

    /** @var Closure(string): ResponseWriterInterface */
    private Closure $writerFactory;

    /**
     * @param callable(): HttpRequest|null $requestFactory
     * @param callable(string): ResponseWriterInterface|null $writerFactory
     * @param callable(callable(): void): bool|null $workerRequestHandler
     */
    public function __construct(
        private FrankenPhpOptions $options,
        ?callable $requestFactory = null,
        ?callable $writerFactory = null,
        ?callable $workerRequestHandler = null,
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

    /** @return Closure(callable(): void): bool|null */
    private static function nativeWorkerRequestHandler(): ?Closure
    {
        if (!function_exists('frankenphp_handle_request')) {
            return null;
        }

        /** @var Closure(callable(): void): bool $handler */
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

        for ($handled = 0; $this->options->maxRequests === 0 || $handled < $this->options->maxRequests; ++$handled) {
            $keepRunning = ($this->workerRequestHandler)(function () use ($application): void {
                $this->handleCurrentRequest($application);
            });
            gc_collect_cycles();
            if (!$keepRunning) {
                return;
            }
        }
    }
}
