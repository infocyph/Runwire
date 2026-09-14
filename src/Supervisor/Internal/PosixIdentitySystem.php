<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

/**
 * Native POSIX identity operations used by supervised worker privilege dropping.
 *
 * @internal
 */
final readonly class PosixIdentitySystem implements IdentitySystemInterface
{
    /**
     * Return the process effective user ID.
     */
    public function effectiveUid(): int
    {
        return posix_geteuid();
    }

    /**
     * Return the process effective group ID.
     */
    public function effectiveGid(): int
    {
        return posix_getegid();
    }

    /**
     * Resolve one passwd entry by user ID.
     */
    public function passwd(int $uid): array|false
    {
        return posix_getpwuid($uid);
    }

    /**
     * Initialize supplementary groups for the target identity.
     */
    public function initGroups(string $username, int $gid): bool
    {
        return posix_initgroups($username, $gid);
    }

    /**
     * Set the process primary group ID.
     */
    public function setGid(int $gid): bool
    {
        return posix_setgid($gid);
    }

    /**
     * Set the process user ID.
     */
    public function setUid(int $uid): bool
    {
        return posix_setuid($uid);
    }
}
