<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Infocyph\Runwire\Cancellation\Internal\CancellationState;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use OverflowException;
use WeakReference;

/** Creates and owns a cancellation token and its mutable cancellation state. */
final class CancellationSource
{
    private const int MAX_LINKED_CHILDREN = 1_000_000;

    private readonly CancellationState $state;

    private readonly CancellationToken $token;

    /** @var array<int, WeakReference<CancellationSource>> */
    private array $children = [];

    private int $nextChildId = 1;

    private ?CancellationSubscription $parentSubscription = null;

    /** @var WeakReference<CancellationSource>|null */
    private ?WeakReference $parentSource = null;

    private ?int $parentSourceChildId = null;

    /** Creates a cancellation source using the supplied deadline or an unlimited deadline. */
    public function __construct(?RequestDeadline $deadline = null)
    {
        $this->state = new CancellationState($deadline ?? RequestDeadline::unlimited());
        $this->token = new CancellationToken($this->state);
        $weakSource = WeakReference::create($this);
        $this->state->setTerminalHook(static function (CancellationReason $reason) use ($weakSource): void {
            $source = $weakSource->get();
            if ($source instanceof self) {
                $source->onTerminal($reason);
            }
        });
    }

    /** Creates a source linked to a parent token and the earliest applicable deadline. */
    public static function linked(
        CancellationToken $parent,
        ?RequestDeadline $deadline = null,
    ): self {
        $source = new self(self::earliestDeadline(
            $parent->deadline(),
            $deadline ?? RequestDeadline::unlimited(),
        ));
        $weakSource = WeakReference::create($source);
        $subscription = $parent->onCancel(static function (CancellationToken $token) use ($weakSource): void {
            $child = $weakSource->get();
            $reason = $token->reason();
            if ($child instanceof self && $reason !== null) {
                $child->cancel($reason);
            }
        });
        if ($subscription->active()) {
            $source->parentSubscription = $subscription;
        }

        return $source;
    }

    /** Cancels the source once and propagates cancellation to structured child sources. */
    public function cancel(CancellationReason $reason): bool
    {
        return $this->state->cancel($reason, $this->token);
    }

    /** Creates a structured child source linked without consuming the public observer budget. */
    public function child(?RequestDeadline $deadline = null): self
    {
        $child = new self(self::earliestDeadline(
            $this->token->deadline(),
            $deadline ?? RequestDeadline::unlimited(),
        ));
        $reason = $this->token->reason();
        if ($reason !== null) {
            $child->cancel($reason);

            return $child;
        }
        if ($this->state->disposed()) {
            return $child;
        }

        $id = $this->registerChild($child);
        $child->parentSource = WeakReference::create($this);
        $child->parentSourceChildId = $id;

        return $child;
    }

    /** Disposes the source and releases parent links, structured children, and observers. */
    public function dispose(): void
    {
        $this->unlinkParentSubscription();
        $this->unlinkParentSource();
        $this->detachChildren();
        $this->state->dispose();
    }

    /**
     * Binds the effective deadline and immediately refreshes cancellation state.
     *
     * @internal
     */
    public function setDeadline(RequestDeadline $deadline): void
    {
        $this->state->bindDeadline($deadline);
        $this->token->isCancelled();
    }

    /** Returns the token that observes this source's cancellation state. */
    public function token(): CancellationToken
    {
        return $this->token;
    }

    private static function earliestDeadline(RequestDeadline $parent, RequestDeadline $requested): RequestDeadline
    {
        $parentAt = $parent->monotonicNanoseconds;
        $requestedAt = $requested->monotonicNanoseconds;

        if ($parentAt === null) {
            return $requested;
        }
        if ($requestedAt === null) {
            return $parent;
        }

        return new RequestDeadline(min($parentAt, $requestedAt));
    }

    private function cancelChildren(CancellationReason $reason): void
    {
        $children = $this->children;
        $this->children = [];

        foreach ($children as $child) {
            $source = $child->get();
            if ($source instanceof self) {
                $source->cancel($reason);
            }
        }
    }

    private function detachChildren(): void
    {
        $children = $this->children;
        $this->children = [];

        foreach ($children as $id => $child) {
            $source = $child->get();
            if ($source instanceof self) {
                $source->detachParentSource($this, $id);
            }
        }
    }

    private function detachParentSource(self $parent, int $id): void
    {
        if ($this->parentSourceChildId !== $id || $this->parentSource?->get() !== $parent) {
            return;
        }

        $this->parentSource = null;
        $this->parentSourceChildId = null;
    }

    private function onTerminal(CancellationReason $reason): void
    {
        $this->unlinkParentSubscription();
        $this->unlinkParentSource();
        $this->cancelChildren($reason);
    }

    private function pruneChildren(): void
    {
        foreach ($this->children as $id => $child) {
            if ($child->get() === null) {
                unset($this->children[$id]);
            }
        }
    }

    private function registerChild(self $child): int
    {
        $this->pruneChildren();
        if (count($this->children) >= self::MAX_LINKED_CHILDREN) {
            throw new OverflowException('Cancellation structured child limit exceeded.');
        }
        if ($this->nextChildId === PHP_INT_MAX) {
            throw new OverflowException('Cancellation structured child handle space is exhausted.');
        }

        $id = $this->nextChildId++;
        $this->children[$id] = WeakReference::create($child);

        return $id;
    }

    private function unlinkParentSource(): void
    {
        $parent = $this->parentSource?->get();
        $id = $this->parentSourceChildId;
        $this->parentSource = null;
        $this->parentSourceChildId = null;

        if ($parent instanceof self && $id !== null) {
            $parent->unregisterChild($id, $this);
        }
    }

    private function unlinkParentSubscription(): void
    {
        $this->parentSubscription?->unsubscribe();
        $this->parentSubscription = null;
    }

    private function unregisterChild(int $id, self $child): void
    {
        $registered = $this->children[$id] ?? null;
        if ($registered?->get() === $child) {
            unset($this->children[$id]);
        }
    }
}
