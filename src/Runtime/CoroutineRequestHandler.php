<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Closure;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;

/**
 * Adapts an HTTP handler to execute within a request-scoped coroutine runtime.
 */
final readonly class CoroutineRequestHandler
{
    /** @var Closure(HttpRequest, ResponseWriterInterface, CoroutineScope): void */
    private Closure $handler;

    /** @param callable(HttpRequest, ResponseWriterInterface, CoroutineScope): void $handler */
    public function __construct(
        private CoroutineRuntime $runtime,
        callable $handler,
    ) {
        $this->handler = Closure::fromCallable($handler);
    }

    /**
     * Executes one HTTP request inside a coroutine scope.
     */
    public function __invoke(HttpRequest $request, ResponseWriterInterface $writer): void
    {
        $this->runtime->runRequest(
            $request->context,
            function (CoroutineScope $scope) use ($request, $writer): void {
                ($this->handler)($request, $writer, $scope);
            },
        );
    }
}
