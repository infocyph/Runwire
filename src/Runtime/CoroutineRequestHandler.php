<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Closure;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Task;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\LoopInterface;
use Throwable;

/**
 * Adapts an HTTP handler to execute within a request-scoped coroutine runtime.
 */
final class CoroutineRequestHandler implements LoopAwareRequestHandlerInterface
{
    /** @var Closure(HttpRequest, ResponseWriterInterface, CoroutineScope): void */
    private readonly Closure $handler;

    private ?CoroutineRuntime $attachedRuntime = null;

    /** @param callable(HttpRequest, ResponseWriterInterface, CoroutineScope): void $handler */
    public function __construct(
        private readonly CoroutineRuntime $runtime,
        callable $handler,
    ) {
        $this->handler = Closure::fromCallable($handler);
    }

    /**
     * Executes one HTTP request inside a coroutine scope.
     */
    public function __invoke(HttpRequest $request, ResponseWriterInterface $writer): void
    {
        $callback = function (CoroutineScope $scope) use ($request, $writer): void {
            ($this->handler)($request, $writer, $scope);
        };
        if ($this->attachedRuntime === null) {
            $this->runtime->runRequest($request->context, $callback);

            return;
        }

        $request->context->beginOwnedWork();

        try {
            $this->attachedRuntime->attachRequest(
                $request->context,
                $callback,
                static function (Task $task) use ($request): void {
                    $request->context->finishOwnedWork($task->failure());
                },
            );
        } catch (Throwable $error) {
            $request->context->finishOwnedWork($error);

            throw $error;
        }
    }

    /**
     * Attach request scopes to a runtime-owned loop without taking loop ownership.
     */
    public function attachLoop(LoopInterface $loop): void
    {
        $this->attachedRuntime = $this->runtime->withLoop($loop);
    }
}
