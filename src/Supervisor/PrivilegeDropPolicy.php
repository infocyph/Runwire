<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use Infocyph\Runwire\Exception\SupervisorException;
use Infocyph\Runwire\Supervisor\Internal\PosixIdentitySystem;
use Infocyph\Runwire\Supervisor\Internal\PrivilegeDropper;
use InvalidArgumentException;

/**
 * Configures and applies optional worker UID and GID privilege reduction.
 */
final readonly class PrivilegeDropPolicy
{
    /**
     * Create a worker identity policy.
     */
    public function __construct(
        public ?int $uid = null,
        public ?int $gid = null,
    ) {
        if ($uid !== null && $uid < 0) {
            throw new InvalidArgumentException('Worker UID must be null or non-negative.');
        }
        if ($gid !== null && $gid < 0) {
            throw new InvalidArgumentException('Worker GID must be null or non-negative.');
        }
    }

    /**
     * Apply the configured identity to the current worker process.
     */
    public function apply(): void
    {
        if (!$this->enabled()) {
            return;
        }

        $this->assertSupported();
        new PrivilegeDropper(new PosixIdentitySystem())->apply($this->uid, $this->gid);
    }

    /**
     * Verify that the runtime can perform the configured identity changes.
     */
    public function assertSupported(): void
    {
        if (!$this->enabled()) {
            return;
        }

        $this->assertRequiredFunctions();
        $this->assertTransitionPermission($this->resolveTargetGid());
    }

    /**
     * Determine whether any identity change is configured.
     */
    public function enabled(): bool
    {
        return $this->uid !== null || $this->gid !== null;
    }

    private function assertRequiredFunctions(): void
    {
        foreach ($this->requiredFunctions() as $function) {
            if (!function_exists($function)) {
                throw new SupervisorException(sprintf(
                    'Worker identity policy requires POSIX function "%s".',
                    $function,
                ));
            }
        }
    }

    private function assertTransitionPermission(?int $targetGid): void
    {
        $effectiveUid = posix_geteuid();
        if ($effectiveUid === 0) {
            return;
        }

        $uidChange = $this->uid !== null && $this->uid !== $effectiveUid;
        $gidChange = $targetGid !== null && $targetGid !== posix_getegid();
        if ($uidChange || $gidChange) {
            throw new SupervisorException('Changing worker identity requires the native master to have sufficient permissions.');
        }
    }

    /** @return list<string> */
    private function requiredFunctions(): array
    {
        $functions = ['posix_geteuid', 'posix_getegid', 'posix_setuid', 'posix_setgid'];
        if ($this->uid !== null) {
            $functions[] = 'posix_getpwuid';
            $functions[] = 'posix_initgroups';
        }

        return $functions;
    }

    private function resolveTargetGid(): ?int
    {
        if ($this->uid === null) {
            return $this->gid;
        }

        $passwd = posix_getpwuid($this->uid);
        if ($passwd === false || $passwd['name'] === '') {
            throw new SupervisorException(sprintf('Unable to resolve worker UID %d.', $this->uid));
        }

        return $this->gid ?? $passwd['gid'];
    }
}
