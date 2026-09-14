<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use Closure;
use Infocyph\Runwire\Runtime\AdmissionPolicy;
use Infocyph\Runwire\Supervisor\Enum\WorkerRole;
use InvalidArgumentException;

/**
 * Defines one supervised worker group and its restart, recycle, admission, and identity policies.
 */
final readonly class WorkerGroup
{
    private const int MAX_WORKERS = 1_024;

    /** @var Closure(WorkerContext): void */
    public Closure $bootstrap;

    public bool $reloadable;

    /**
     * Create a worker group definition.
     *
     * @param callable(WorkerContext): void $bootstrap
     */
    public function __construct(
        public string $name,
        public int $count,
        callable $bootstrap,
        public RestartPolicy $restartPolicy = new RestartPolicy(),
        public WorkerRecyclePolicy $recyclePolicy = new WorkerRecyclePolicy(),
        public AdmissionPolicy $admissionPolicy = new AdmissionPolicy(),
        public PrivilegeDropPolicy $privilegeDropPolicy = new PrivilegeDropPolicy(),
        public bool $automaticReady = true,
        public float $readyTimeoutSeconds = 10.0,
        public float $shutdownTimeoutSeconds = 10.0,
        ?bool $reloadable = null,
        public WorkerRole $role = WorkerRole::CUSTOM,
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

        $privilegeDropPolicy->assertSupported();

        /** @var Closure(WorkerContext): void $closure */
        $closure = Closure::fromCallable($bootstrap);
        $this->bootstrap = $closure;
        $this->reloadable = $reloadable ?? $role->defaultReloadable();
    }

    /**
     * Create a worker group from a callback factory.
     *
     * @param callable(WorkerContext): void $factory
     */
    public static function callbacks(
        string $name,
        int $count,
        callable $factory,
        ?RestartPolicy $restartPolicy = null,
        ?WorkerRecyclePolicy $recyclePolicy = null,
        ?AdmissionPolicy $admissionPolicy = null,
        ?PrivilegeDropPolicy $privilegeDropPolicy = null,
        bool $automaticReady = true,
        float $readyTimeoutSeconds = 10.0,
        float $shutdownTimeoutSeconds = 10.0,
        ?bool $reloadable = null,
        WorkerRole $role = WorkerRole::CUSTOM,
    ): self {
        return new self(
            name: $name,
            count: $count,
            bootstrap: $factory,
            restartPolicy: $restartPolicy ?? new RestartPolicy(),
            recyclePolicy: $recyclePolicy ?? new WorkerRecyclePolicy(),
            admissionPolicy: $admissionPolicy ?? new AdmissionPolicy(),
            privilegeDropPolicy: $privilegeDropPolicy ?? new PrivilegeDropPolicy(),
            automaticReady: $automaticReady,
            readyTimeoutSeconds: $readyTimeoutSeconds,
            shutdownTimeoutSeconds: $shutdownTimeoutSeconds,
            reloadable: $reloadable,
            role: $role,
        );
    }
}
