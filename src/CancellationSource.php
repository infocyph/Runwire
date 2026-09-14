<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Infocyph\Runwire\Cancellation\Internal\CancellationState;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use WeakReference;

/** Creates and owns a cancellation token and its mutable cancellation state. */
final class CancellationSource
{
    private readonly CancellationState $state;

    private readonly CancellationToken $token;

    private ?CancellationSubscription $parentSubscription = null;

    /** Creates a cancellation source using the supplied deadline or an unlimited deadline. */
    public function __construct(?RequestDeadline $deadline = null)
    {
        $this->state = new CancellationState($deadline ?? RequestDeadline::unlimited());
        $this->token = new CancellationToken($this->state);
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

    /** Cancels the source once and detaches it from any parent source. */
    public function cancel(CancellationReason $reason): bool
    {
        $cancelled = $this->state->cancel($reason, $this->token);
        if ($cancelled) {
            $this->unlinkParent();
        }

        return $cancelled;
    }

    /** Creates a child source linked to this source's token. */
    public function child(?RequestDeadline $deadline = null): self
    {
        return self::linked($this->token, $deadline);
    }

    /** Disposes the source and releases its parent subscription and observers. */
    public function dispose(): void
    {
        $this->unlinkParent();
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

    private function unlinkParent(): void
    {
        $this->parentSubscription?->unsubscribe();
        $this->parentSubscription = null;
    }
}
