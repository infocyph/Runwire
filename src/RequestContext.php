<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Closure;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use Throwable;

/**
 * Carries per-request runtime binding, cancellation, deadlines, and bounded attributes.
 */
final class RequestContext
{
    private const int DEFAULT_MAX_ATTRIBUTES = 64;

    public readonly CancellationToken $cancellation;

    private readonly CancellationSource $cancellationSource;

    private bool $active = false;

    /** @var array<string, mixed> */
    private array $attributes = [];

    private bool $completed = false;

    private ?Throwable $ownedWorkFailure = null;

    private int $ownedWorkCount = 0;

    /** @var Closure(): void|null */
    private ?Closure $ownedWorkSettled = null;

    private function __construct(
        private RuntimeContext $runtime,
        public readonly string $requestId,
        public readonly int $startMonotonicNanoseconds,
        private RequestDeadline $deadline,
        private bool $bound,
        private readonly int $maxAttributes = self::DEFAULT_MAX_ATTRIBUTES,
    ) {
        self::assertRequestId($requestId);
        if ($startMonotonicNanoseconds < 0) {
            throw new InvalidArgumentException('Request start time must be non-negative.');
        }
        if ($maxAttributes < 1 || $maxAttributes > 1_024) {
            throw new InvalidArgumentException('Request context attribute limit must be between 1 and 1024.');
        }

        $this->cancellationSource = new CancellationSource($this->deadline);
        $this->cancellation = $this->cancellationSource->token();
    }

    /**
     * Creates a runtime-bound request context using the supplied execution policy.
     */
    public static function create(
        RuntimeContext $runtime,
        RequestExecutionPolicy $policy = new RequestExecutionPolicy(),
        ?string $requestId = null,
        ?int $startNanoseconds = null,
        int $maxAttributes = self::DEFAULT_MAX_ATTRIBUTES,
    ): self {
        $start = $startNanoseconds ?? self::nowNanoseconds();

        return new self(
            $runtime,
            $policy->requestId($runtime, $requestId),
            $start,
            $policy->deadline($start),
            true,
            $maxAttributes,
        );
    }

    /** @internal Used for request objects constructed outside an active runtime. */
    public static function standalone(?string $requestId = null, ?int $startNanoseconds = null): self
    {
        $start = $startNanoseconds ?? self::nowNanoseconds();

        return new self(
            RuntimeContext::standalone(),
            $requestId ?? self::generateRequestId(),
            $start,
            RequestDeadline::unlimited(),
            false,
        );
    }

    /**
     * Claims this single-use context for one active request lifecycle.
     */
    public function activate(RuntimeContext $runtime, RequestExecutionPolicy $policy): void
    {
        if ($this->completed) {
            throw new LogicException('Completed request context cannot be activated.');
        }
        if ($this->active) {
            throw new LogicException('Request context is already active.');
        }
        if ($this->bound) {
            if ($this->runtime !== $runtime) {
                throw new LogicException('Request context is already bound to a different runtime context.');
            }

            $this->active = true;

            return;
        }

        $deadline = $policy->deadline($this->startMonotonicNanoseconds);
        $this->runtime = $runtime;
        $this->deadline = $deadline;
        $this->bound = true;
        $this->active = true;
        $this->cancellationSource->setDeadline($deadline);
    }

    /**
     * Returns a request attribute or the supplied default value when the key is absent.
     */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->attributes)
            ? $this->attributes[$key]
            : $default;
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /** @internal */
    public function beginOwnedWork(): void
    {
        if ($this->completed) {
            throw new LogicException('Completed request context cannot own asynchronous work.');
        }

        ++$this->ownedWorkCount;
    }

    /**
     * Cancels request work with the supplied reason.
     */
    public function cancel(CancellationReason $reason): bool
    {
        return $this->cancellationSource->cancel($reason);
    }

    /**
     * Reports whether the request is cancelled at the supplied or current monotonic time.
     */
    public function cancelled(?int $nowNanoseconds = null): bool
    {
        return $this->cancellation->isCancelled($nowNanoseconds);
    }

    /**
     * Completes the context and releases request-scoped state.
     */
    public function complete(): void
    {
        if ($this->completed) {
            return;
        }
        if ($this->ownedWorkCount > 0) {
            throw new LogicException('Request context cannot complete while owned asynchronous work is active.');
        }

        $this->attributes = [];
        $this->cancellationSource->dispose();
        $this->active = false;
        $this->completed = true;
        $this->ownedWorkFailure = null;
        $this->ownedWorkSettled = null;
    }

    /** @internal */
    public function finishOwnedWork(?Throwable $failure = null): void
    {
        if ($this->ownedWorkCount < 1) {
            throw new LogicException('Request context has no owned asynchronous work to finish.');
        }

        $this->ownedWorkFailure ??= $failure;
        --$this->ownedWorkCount;
        if ($this->ownedWorkCount === 0) {
            ($this->ownedWorkSettled)?->__invoke();
        }
    }

    /**
     * Reports whether the request context has completed.
     */
    public function completed(): bool
    {
        return $this->completed;
    }

    /** @internal */
    public function hasOwnedWork(): bool
    {
        return $this->ownedWorkCount > 0;
    }

    /**
     * Returns the request execution deadline.
     */
    public function deadline(): RequestDeadline
    {
        return $this->deadline;
    }

    /**
     * Reports whether a request attribute exists.
     */
    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * Removes a request attribute when present.
     */
    public function removeAttribute(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /** @internal */
    public function observeOwnedWorkSettled(callable $callback): void
    {
        $this->ownedWorkSettled = $callback(...);
    }

    /** @internal */
    public function ownedWorkFailure(): ?Throwable
    {
        return $this->ownedWorkFailure;
    }

    /**
     * Returns the runtime context currently bound to this request.
     */
    public function runtime(): RuntimeContext
    {
        return $this->runtime;
    }

    /**
     * Stores a bounded request attribute.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        if ($this->completed) {
            throw new LogicException('Completed request context cannot accept attributes.');
        }
        self::assertAttributeKey($key);
        if (!array_key_exists($key, $this->attributes) && count($this->attributes) >= $this->maxAttributes) {
            throw new OverflowException('Request context attribute limit exceeded.');
        }

        $this->attributes[$key] = $value;
    }

    private static function assertAttributeKey(string $key): void
    {
        if ($key === '' || strlen($key) > 128 || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new InvalidArgumentException('Request context attribute key must be 1-128 bytes without control characters.');
        }
    }

    private static function assertRequestId(string $requestId): void
    {
        if ($requestId === '' || strlen($requestId) > 128 || preg_match('/[\x00-\x1F\x7F]/', $requestId) === 1) {
            throw new InvalidArgumentException('Request ID must be 1-128 bytes without control characters.');
        }
    }

    private static function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function nowNanoseconds(): int
    {
        $now = hrtime(true);

        return is_int($now) ? $now : (int) $now;
    }
}
