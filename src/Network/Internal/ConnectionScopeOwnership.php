<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\RequestDeadline;
use WeakReference;

/** @internal Binds the established stream lifetime to one cancellation owner. */
final class ConnectionScopeOwnership
{
    private ?int $deadlineTimer = null;

    private bool $disposed = false;

    private ?CancellationSubscription $subscription = null;

    private function __construct(private readonly LoopInterface $loop) {}

    /** Attach exactly one token and the earliest inherited or explicit deadline. */
    public static function attach(
        Connection $connection,
        LoopInterface $loop,
        CancellationToken $token,
        ?RequestDeadline $deadline,
    ): self {
        $binding = new self($loop);
        $weak = WeakReference::create($connection);
        $connection->onClose(static function () use ($binding): void {
            $binding->dispose();
        });

        $subscription = $token->onCancel(static function () use ($weak): void {
            $active = $weak->get();
            if ($active instanceof Connection) {
                $active->abort(CloseReason::LOCAL_ABORT);
            }
        });
        if ($binding->disposed) {
            $subscription->unsubscribe();

            return $binding;
        }
        $binding->subscription = $subscription;

        $limit = self::earliest($token->deadline(), $deadline);
        $remaining = $limit->remainingSeconds();
        if ($remaining === null) {
            return $binding;
        }
        if ($remaining <= 0) {
            $connection->abort(CloseReason::LIFETIME_TIMEOUT);

            return $binding;
        }
        $timer = $loop->delay($remaining, static function () use ($weak): void {
            $active = $weak->get();
            if ($active instanceof Connection) {
                $active->abort(CloseReason::LIFETIME_TIMEOUT);
            }
        });
        if ($binding->disposed) {
            $loop->cancel($timer);
        } else {
            $binding->deadlineTimer = $timer;
        }

        return $binding;
    }

    /** Release the owner observer and deadline handle on all close paths. */
    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;
        $this->subscription?->unsubscribe();
        $this->subscription = null;
        if ($this->deadlineTimer !== null) {
            $this->loop->cancel($this->deadlineTimer);
            $this->deadlineTimer = null;
        }
    }

    private static function earliest(RequestDeadline $inherited, ?RequestDeadline $requested): RequestDeadline
    {
        if ($requested?->monotonicNanoseconds === null) {
            return $inherited;
        }
        if ($inherited->monotonicNanoseconds === null) {
            return $requested;
        }

        return $requested->monotonicNanoseconds < $inherited->monotonicNanoseconds ? $requested : $inherited;
    }
}
