<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use InvalidArgumentException;

final readonly class ReloadPolicy
{
    public function __construct(
        public int $maxUnavailable = 0,
        public int $maxSurge = 1,
        public float $replacementReadyTimeoutSeconds = 10.0,
        public float $drainTimeoutSeconds = 30.0,
    ) {
        if ($maxUnavailable < 0 || $maxUnavailable > 1_024) {
            throw new InvalidArgumentException('Reload maxUnavailable must be between 0 and 1024.');
        }
        if ($maxSurge < 1 || $maxSurge > 1_024) {
            throw new InvalidArgumentException('Reload maxSurge must be between 1 and 1024.');
        }
        if (!is_finite($replacementReadyTimeoutSeconds) || $replacementReadyTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('Replacement ready timeout must be finite and positive.');
        }
        if (!is_finite($drainTimeoutSeconds) || $drainTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('Reload drain timeout must be finite and positive.');
        }
    }
}
