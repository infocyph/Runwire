<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Closure;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;

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
