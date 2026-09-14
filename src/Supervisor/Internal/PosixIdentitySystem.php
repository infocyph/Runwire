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
    public function effectiveUid(): int
    {
        return posix_geteuid();
    }

    public function effectiveGid(): int
    {
        return posix_getegid();
    }

    public function passwd(int $uid): array|false
    {
        return posix_getpwuid($uid);
    }

    public function initGroups(string $username, int $gid): bool
    {
        return posix_initgroups($username, $gid);
    }

    public function setGid(int $gid): bool
    {
        return posix_setgid($gid);
    }

    public function setUid(int $uid): bool
    {
        return posix_setuid($uid);
    }
}
