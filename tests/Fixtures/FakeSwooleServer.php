<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Fixtures;

use Closure;

final class FakeSwooleServer
{
    /** @var array<string, bool|int> */
    public array $settings = [];

    public bool $shutdownCalled = false;

    /** @var list<int> */
    public array $workerIds = [0];

    /** @var Closure(): void|null */
    public ?Closure $startHook = null;

    /** @var Closure(object, object): void|null */
    private ?Closure $requestHandler = null;

    /** @var array<string, Closure> */
    private array $events = [];

    public function __construct(
        public readonly FakeSwooleRequest $request,
        public readonly FakeSwooleResponse $response,
    ) {}

    public function on(string $event, callable $handler): bool
    {
        $this->events[strtolower($event)] = Closure::fromCallable($handler);
        if (strtolower($event) === 'request') {
            $this->requestHandler = Closure::fromCallable($handler);
        }

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

        foreach ($this->workerIds as $workerId) {
            try {
                ($this->events['workerstart'] ?? null)?->__invoke($this, $workerId);
                ($this->requestHandler)($this->request, $this->response);
                ($this->startHook)?->__invoke();
            } finally {
                ($this->events['workerstop'] ?? null)?->__invoke();
            }
        }

        return true;
    }
}
