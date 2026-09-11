<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use Closure;
use InvalidArgumentException;

final readonly class WorkerGroup
{
    private const int MAX_WORKERS = 1_024;

    /** @var Closure(WorkerContext): void */
    public Closure $bootstrap;

    public function __construct(
        public string $name,
        public int $count,
        callable $bootstrap,
        public RestartPolicy $restartPolicy = new RestartPolicy(),
        public bool $automaticReady = true,
        public float $readyTimeoutSeconds = 10.0,
        public float $shutdownTimeoutSeconds = 10.0,
    ) {
        if ($name === '' || strlen($name) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/D', $name) !== 1) {
            throw new InvalidArgumentException('Worker group name must be 1-128 safe identifier characters.');
        }

        if ($count < 1 || $count > self::MAX_WORKERS) {
            throw new InvalidArgumentException(sprintf(
                'Worker count must be between 1 and %d.',
                self::MAX_WORKERS,
            ));
        }

        if (!is_finite($readyTimeoutSeconds) || $readyTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('Worker ready timeout must be finite and positive.');
        }

        if (!is_finite($shutdownTimeoutSeconds) || $shutdownTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('Worker shutdown timeout must be finite and positive.');
        }

        $this->bootstrap = Closure::fromCallable($bootstrap);
    }

    public static function callbacks(
        string $name,
        int $count,
        callable $factory,
        ?RestartPolicy $restartPolicy = null,
        bool $automaticReady = true,
        float $readyTimeoutSeconds = 10.0,
        float $shutdownTimeoutSeconds = 10.0,
    ): self {
        return new self(
            name: $name,
            count: $count,
            bootstrap: $factory,
            restartPolicy: $restartPolicy ?? new RestartPolicy(),
            automaticReady: $automaticReady,
            readyTimeoutSeconds: $readyTimeoutSeconds,
            shutdownTimeoutSeconds: $shutdownTimeoutSeconds,
        );
    }
}
