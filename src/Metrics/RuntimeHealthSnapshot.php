<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

/**
 * Carries liveness, readiness, health, and draining state for a runtime.
 */
final readonly class RuntimeHealthSnapshot
{
    /**
     * Create an immutable runtime health sample.
     */
    public function __construct(
        public bool $live,
        public bool $ready,
        public bool $healthy,
        public bool $draining,
    ) {}

    /** @return array{live: bool, ready: bool, healthy: bool, draining: bool} */
    public function toArray(): array
    {
        return [
            'live' => $this->live,
            'ready' => $this->ready,
            'healthy' => $this->healthy,
            'draining' => $this->draining,
        ];
    }
}
