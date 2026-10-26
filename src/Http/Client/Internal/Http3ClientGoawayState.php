<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Closure;

/** @internal Peer GOAWAY cutoff and stream-owner retirement on one HTTP/3 connection. */
final class Http3ClientGoawayState
{
    private ?int $cutoff = null;

    /**
     * Drain queued admissions and only the in-flight request IDs disallowed by GOAWAY.
     *
     * @param array<int, Http3ClientSiblingExchange> $exchanges
     * @param Closure(int):void $rejectPending
     * @param Closure(int):void $rejectActive
     */
    public function drain(
        ?int $peerGoawayStreamId,
        Http3ClientAdmissionGate $admission,
        array $exchanges,
        Closure $rejectPending,
        Closure $rejectActive,
    ): void {
        if ($peerGoawayStreamId === null) {
            return;
        }
        $this->cutoff = $peerGoawayStreamId;
        foreach ($admission->drain() as $id) {
            $rejectPending($id);
        }
        foreach ($exchanges as $id => $state) {
            if ($state->stream > 0 && $this->refuses($state->wireStreamId)) {
                $rejectActive($id);
            }
        }
    }
    /** Whether the peer refused a newly allocated HTTP/3 request stream. */
    public function refuses(int $wireStreamId): bool
    {
        return $this->cutoff !== null && $wireStreamId >= $this->cutoff;
    }

}
