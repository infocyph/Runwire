<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use Infocyph\Runwire\Network\Enum\DialAttemptPhase;
use LogicException;

/**
 * Guards the pending transport lifecycle. Does not perform network I/O.
 *
 * A future dialer owns each pending socket until one verified stream is handed
 * to the established connection factory. No terminal phase can be re-entered.
 */
final class DialAttemptLifecycle
{
    private DialAttemptPhase $phase = DialAttemptPhase::NEW;

    /** Return the current dial-attempt lifecycle phase. */
    public function phase(): DialAttemptPhase
    {
        return $this->phase;
    }

    /** Whether the transport has been verified and is eligible for ownership transfer. */
    public function readyForHandoff(): bool
    {
        return $this->phase === DialAttemptPhase::READY;
    }

    /** Advance to a valid phase, rejecting duplicate completion or resurrection. */
    public function transition(DialAttemptPhase $next): void
    {
        $allowed = match ($this->phase) {
            DialAttemptPhase::NEW => [
                DialAttemptPhase::RESOLVING,
                DialAttemptPhase::CONNECTING,
                DialAttemptPhase::CANCELLED,
                DialAttemptPhase::FAILED,
            ],
            DialAttemptPhase::RESOLVING => [
                DialAttemptPhase::CONNECTING,
                DialAttemptPhase::CANCELLED,
                DialAttemptPhase::FAILED,
            ],
            DialAttemptPhase::CONNECTING => [
                DialAttemptPhase::HANDSHAKING,
                DialAttemptPhase::READY,
                DialAttemptPhase::CANCELLED,
                DialAttemptPhase::FAILED,
            ],
            DialAttemptPhase::HANDSHAKING => [
                DialAttemptPhase::READY,
                DialAttemptPhase::CANCELLED,
                DialAttemptPhase::FAILED,
            ],
            DialAttemptPhase::READY => [
                DialAttemptPhase::HANDED_OFF,
                DialAttemptPhase::CANCELLED,
                DialAttemptPhase::FAILED,
            ],
            DialAttemptPhase::CANCELLED,
            DialAttemptPhase::FAILED,
            DialAttemptPhase::HANDED_OFF => [],
        };

        if (!in_array($next, $allowed, true)) {
            throw new LogicException(sprintf(
                'Illegal outbound dial phase transition %s -> %s.',
                $this->phase->value,
                $next->value,
            ));
        }
        $this->phase = $next;
    }
}
