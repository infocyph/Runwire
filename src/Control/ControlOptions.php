<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Control;

use InvalidArgumentException;

final readonly class ControlOptions
{
    public function __construct(
        public string $path,
        public int $permissions = 0o600,
        public int $maxRequestBytes = 8_192,
        public int $maxResponseBytes = 1_048_576,
        public int $maxConnections = 16,
        public float $idleTimeoutSeconds = 10.0,
        public float $lifetimeTimeoutSeconds = 60.0,
    ) {
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException('Control socket path must be absolute.');
        }
        if (($permissions & 0o600) !== 0o600 || ($permissions & 0o007) !== 0 || $permissions > 0o770) {
            throw new InvalidArgumentException('Control socket permissions must grant owner read/write and no world access.');
        }
        if ($maxRequestBytes < 256 || $maxRequestBytes > 65_536) {
            throw new InvalidArgumentException('Control request limit must be between 256 and 65536 bytes.');
        }
        if ($maxResponseBytes < 1_024 || $maxResponseBytes > 8_388_608) {
            throw new InvalidArgumentException('Control response limit must be between 1024 and 8388608 bytes.');
        }
        if ($maxConnections < 1 || $maxConnections > 256) {
            throw new InvalidArgumentException('Control connection limit must be between 1 and 256.');
        }
        foreach ([$idleTimeoutSeconds, $lifetimeTimeoutSeconds] as $seconds) {
            if (!is_finite($seconds) || $seconds <= 0) {
                throw new InvalidArgumentException('Control timeouts must be finite and positive.');
            }
        }
    }
}
