<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Fixtures;

use Closure;

/** State shared with the test-local implementation of the native Swoole callbacks. */
abstract class FakeSwooleServer
{
    /** @var array<string, bool|int> */
    public array $settings = [];

    public bool $shutdownCalled = false;

    /** @var list<int> */
    public array $workerIds = [0];

    /** @var Closure(): void|null */
    public ?Closure $startHook = null;

    public function __construct(
        public readonly FakeSwooleRequest $request,
        public readonly FakeSwooleResponse $response,
    ) {}
}
