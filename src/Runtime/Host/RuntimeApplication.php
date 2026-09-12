<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Closure;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;

final class RuntimeApplication
{
    /** @var Closure(HttpRequest, ResponseWriterInterface): void */
    private readonly Closure $handler;

    /** @var Closure(): void|null */
    private readonly ?Closure $requestCleanup;

    /** @var Closure(): void|null */
    private readonly ?Closure $shutdownCallback;

    private bool $shutdown = false;

    /**
     * @param callable(HttpRequest, ResponseWriterInterface): void $handler
     * @param callable(): void|null $requestCleanup
     * @param callable(): void|null $shutdown
     */
    public function __construct(callable $handler, ?callable $requestCleanup = null, ?callable $shutdown = null)
    {
        $this->handler = Closure::fromCallable($handler);
        $this->requestCleanup = $requestCleanup === null ? null : Closure::fromCallable($requestCleanup);
        $this->shutdownCallback = $shutdown === null ? null : Closure::fromCallable($shutdown);
    }

    public function handle(HttpRequest $request, ResponseWriterInterface $writer): void
    {
        try {
            ($this->handler)($request, $writer);
        } finally {
            ($this->requestCleanup)?->__invoke();
        }
    }

    public function shutdown(): void
    {
        if ($this->shutdown) {
            return;
        }

        $this->shutdown = true;
        ($this->shutdownCallback)?->__invoke();
    }
}
