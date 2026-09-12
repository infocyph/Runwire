<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Fixtures;

use Closure;

final class FakeSwooleServer
{
    /** @var array<string, bool|int> */
    public array $settings = [];

    public bool $shutdownCalled = false;

    /** @var Closure(): void|null */
    public ?Closure $startHook = null;

    /** @var Closure(object, object): void|null */
    private ?Closure $requestHandler = null;

    public function __construct(
        public readonly FakeSwooleRequest $request,
        public readonly FakeSwooleResponse $response,
    ) {}

    public function on(string $event, callable $handler): bool
    {
        if (strtolower($event) !== 'request') {
            return false;
        }

        $this->requestHandler = Closure::fromCallable($handler);

        return true;
    }

    /** @param array<string, bool|int> $settings */
    public function set(array $settings): bool
    {
        $this->settings = $settings;

        return true;
    }

    public function shutdown(): bool
    {
        $this->shutdownCalled = true;

        return true;
    }

    public function start(): bool
    {
        if ($this->requestHandler === null) {
            return false;
        }

        ($this->requestHandler)($this->request, $this->response);
        ($this->startHook)?->__invoke();

        return true;
    }
}
