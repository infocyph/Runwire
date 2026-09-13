<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

final readonly class RuntimeHealthSnapshot
{
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
