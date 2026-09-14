<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Closure;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;

/**
 * Bundles optional application lifecycle callbacks and request resetters.
 */
final readonly class ApplicationLifecycleHooks
{
    /** @var Closure(RuntimeContext): void|null */
    public ?Closure $boot;

    /** @var Closure(RuntimeContext, ShutdownReason): void|null */
    public ?Closure $drain;

    public RequestResetterRegistry $resetters;

    /** @var Closure(RuntimeContext, ShutdownReason): void|null */
    public ?Closure $shutdown;

    /** @var Closure(RuntimeContext): void|null */
    public ?Closure $warmup;

    /**
     * @param callable(RuntimeContext): void|null $boot
     * @param callable(RuntimeContext): void|null $warmup
     * @param callable(RuntimeContext, ShutdownReason): void|null $drain
     * @param callable(RuntimeContext, ShutdownReason): void|null $shutdown
     * @param iterable<RequestResetterInterface> $resetters
     */
    public function __construct(
        ?callable $boot = null,
        ?callable $warmup = null,
        ?callable $drain = null,
        ?callable $shutdown = null,
        iterable $resetters = [],
    ) {
        $this->boot = $boot === null ? null : $boot(...);
        $this->drain = $drain === null ? null : $drain(...);
        $this->resetters = new RequestResetterRegistry($resetters);
        $this->shutdown = $shutdown === null ? null : $shutdown(...);
        $this->warmup = $warmup === null ? null : $warmup(...);
    }
}
