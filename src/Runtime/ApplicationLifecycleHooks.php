<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Closure;
use Infocyph\Runwire\RuntimeContext;

final class ApplicationLifecycleHooks
{
    /** @var Closure(RuntimeContext): void|null */
    public readonly ?Closure $boot;

    /** @var Closure(RuntimeContext): void|null */
    public readonly ?Closure $drain;

    public readonly RequestResetterRegistry $resetters;

    /** @var Closure(RuntimeContext): void|null */
    public readonly ?Closure $shutdown;

    /** @var Closure(RuntimeContext): void|null */
    public readonly ?Closure $warmup;

    /**
     * @param callable(RuntimeContext): void|null $boot
     * @param callable(RuntimeContext): void|null $warmup
     * @param callable(RuntimeContext): void|null $drain
     * @param callable(RuntimeContext): void|null $shutdown
     * @param iterable<RequestResetterInterface> $resetters
     */
    public function __construct(
        ?callable $boot = null,
        ?callable $warmup = null,
        ?callable $drain = null,
        ?callable $shutdown = null,
        iterable $resetters = [],
    ) {
        $this->boot = $boot === null ? null : Closure::fromCallable($boot);
        $this->drain = $drain === null ? null : Closure::fromCallable($drain);
        $this->resetters = new RequestResetterRegistry($resetters);
        $this->shutdown = $shutdown === null ? null : Closure::fromCallable($shutdown);
        $this->warmup = $warmup === null ? null : Closure::fromCallable($warmup);
    }
}
