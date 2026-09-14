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
        (new PrivilegeDropper(new PosixIdentitySystem()))->apply($this->uid, $this->gid);
    }

    /**
     * Verify that the runtime can perform the configured identity changes.
     */
    public function assertSupported(): void
    {
        if (!$this->enabled()) {
            return;
        }

        $required = ['posix_geteuid', 'posix_getegid', 'posix_setuid', 'posix_setgid'];
        if ($this->uid !== null) {
            $required[] = 'posix_getpwuid';
            $required[] = 'posix_initgroups';
        }

        foreach ($required as $function) {
            if (!function_exists($function)) {
                throw new SupervisorException(sprintf(
                    'Worker identity policy requires POSIX function "%s".',
                    $function,
                ));
            }
        }

        $effectiveUid = posix_geteuid();
        $effectiveGid = posix_getegid();
        $targetGid = $this->gid;

        if ($this->uid !== null) {
            $passwd = posix_getpwuid($this->uid);
            if ($passwd === false || !isset($passwd['name'], $passwd['gid']) || !is_string($passwd['name'])) {
                throw new SupervisorException(sprintf('Unable to resolve worker UID %d.', $this->uid));
            }
            $passwdGid = filter_var($passwd['gid'], FILTER_VALIDATE_INT);
            if ($passwd['name'] === '' || $passwdGid === false || $passwdGid < 0) {
                throw new SupervisorException(sprintf('Worker UID %d resolved to an invalid passwd entry.', $this->uid));
            }
            $targetGid ??= $passwdGid;
        }

        $uidChange = $this->uid !== null && $this->uid !== $effectiveUid;
        $gidChange = $targetGid !== null && $targetGid !== $effectiveGid;
        if ($effectiveUid !== 0 && ($uidChange || $gidChange)) {
            throw new SupervisorException('Changing worker identity requires the native master to have sufficient permissions.');
        }
    }

    /**
     * Determine whether any identity change is configured.
     */
    public function enabled(): bool
    {
        return $this->uid !== null || $this->gid !== null;
    }
}
