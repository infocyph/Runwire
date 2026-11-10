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
     * Return the process effective group ID.
     */
    #[\Override]
    public function effectiveGid(): int
    {
        return posix_getegid();
    }

    /**
     * Return the process effective user ID.
     */
    #[\Override]
    public function effectiveUid(): int
    {
        return posix_geteuid();
    }

    /**
     * Initialize supplementary groups for the target identity.
     */
    #[\Override]
    public function initGroups(string $username, int $gid): bool
    {
        return posix_initgroups($username, $gid);
    }

    /**
     * Resolve one passwd entry by user ID.
     */
    #[\Override]
    public function passwd(int $uid): array|false
    {
        return posix_getpwuid($uid);
    }

    /**
     * Set the process primary group ID.
     */
    #[\Override]
    public function setGid(int $gid): bool
    {
        return posix_setgid($gid);
    }

    /**
     * Set the process user ID.
     */
    #[\Override]
    public function setUid(int $uid): bool
    {
        return posix_setuid($uid);
    }
}
