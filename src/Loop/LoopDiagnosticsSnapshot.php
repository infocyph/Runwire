<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Loop;

final readonly class LoopDiagnosticsSnapshot
{
    public function __construct(
        public int $sampledAtMonotonicNanoseconds,
        public int $timersActive = 0,
        public int $deferredBacklog = 0,
        public int $readWatchers = 0,
        public int $writeWatchers = 0,
        public int $lastTickNanoseconds = 0,
        public int $maxTickNanoseconds = 0,
        public int $maxLagNanoseconds = 0,
        public int $callbackOverrunsTotal = 0,
    ) {}
}
