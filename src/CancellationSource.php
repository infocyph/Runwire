<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Infocyph\Runwire\Cancellation\Internal\CancellationState;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use WeakReference;

final class CancellationSource
{
    private ?CancellationSubscription $parentSubscription = null;

    private readonly CancellationState $state;

    private readonly CancellationToken $token;

    public function __construct(?RequestDeadline $deadline = null)
    {
        $this->state = new CancellationState($deadline ?? RequestDeadline::unlimited());
        $this->token = new CancellationToken($this->state);
    }

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

    public function cancel(CancellationReason $reason): bool
    {
        $cancelled = $this->state->cancel($reason, $this->token);
        if ($cancelled) {
            $this->unlinkParent();
        }

        return $cancelled;
    }

    public function child(?RequestDeadline $deadline = null): self
    {
        return self::linked($this->token, $deadline);
    }

    public function dispose(): void
    {
        $this->unlinkParent();
        $this->state->dispose();
    }

    /** @internal */
    public function setDeadline(RequestDeadline $deadline): void
    {
        $this->state->bindDeadline($deadline);
        $this->token->isCancelled();
    }

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
