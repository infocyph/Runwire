<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Driver;

use Closure;
use Infocyph\Runwire\FpmOptions;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\Host\HostDriverInterface;
use Infocyph\Runwire\Runtime\Host\HostRequestFactory;
use Infocyph\Runwire\Runtime\Host\NativePhpResponseWriterFactory;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;

final class FpmDriver implements HostDriverInterface
{
    /** @var Closure(): HttpRequest */
    private readonly Closure $requestFactory;

    /** @var Closure(string): ResponseWriterInterface */
    private readonly Closure $writerFactory;

    /**
     * @param callable(): HttpRequest|null $requestFactory
     * @param callable(string): ResponseWriterInterface|null $writerFactory
     */
    public function __construct(
        FpmOptions $options,
        ?callable $requestFactory = null,
        ?callable $writerFactory = null,
    ) {
        $hostRequestFactory = new HostRequestFactory();
        $nativeWriterFactory = new NativePhpResponseWriterFactory();
        $this->requestFactory = $requestFactory === null
            ? static fn(): HttpRequest => $hostRequestFactory->fromGlobals($options->maxRequestBodyBytes)
            : Closure::fromCallable($requestFactory);
        $this->writerFactory = $writerFactory === null
            ? static fn(string $method): ResponseWriterInterface => $nativeWriterFactory->create($method, $options->maxResponseBytes)
            : Closure::fromCallable($writerFactory);
    }

    public function run(RuntimeApplication $application): void
    {
        try {
            $request = ($this->requestFactory)();
            $application->handle($request, ($this->writerFactory)($request->method));
        } finally {
            $application->shutdown();
        }
    }

    public function stop(): void {}
}
