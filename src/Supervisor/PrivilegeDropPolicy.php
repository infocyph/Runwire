<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use Infocyph\Runwire\Exception\SupervisorException;
use InvalidArgumentException;

final readonly class PrivilegeDropPolicy
{
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

    public function enabled(): bool
    {
        return $this->uid !== null || $this->gid !== null;
    }

    public function assertSupported(): void
    {
        if (!$this->enabled()) {
            return;
        }

        foreach (['posix_geteuid', 'posix_getegid', 'posix_setuid', 'posix_setgid'] as $function) {
            if (!function_exists($function)) {
                throw new SupervisorException('Worker identity policy requires POSIX UID/GID functions.');
            }
        }

        $effectiveUid = posix_geteuid();
        $uidChange = $this->uid !== null && $this->uid !== $effectiveUid;
        $gidChange = $this->gid !== null && $this->gid !== posix_getegid();
        if ($effectiveUid !== 0 && ($uidChange || $gidChange)) {
            throw new SupervisorException('Changing worker identity requires the native master to have sufficient permissions.');
        }
    }

    public function apply(): void
    {
        if (!$this->enabled()) {
            return;
        }

        $this->assertSupported();
        if ($this->gid !== null && posix_getegid() !== $this->gid && !posix_setgid($this->gid)) {
            throw new SupervisorException(sprintf('Unable to set worker GID to %d.', $this->gid));
        }
        if ($this->uid !== null && posix_geteuid() !== $this->uid && !posix_setuid($this->uid)) {
            throw new SupervisorException(sprintf('Unable to set worker UID to %d.', $this->uid));
        }
    }
}
