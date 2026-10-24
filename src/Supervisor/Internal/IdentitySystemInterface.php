<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

/**
 * Provides the operating-system identity operations required by worker privilege dropping.
 *
 * @internal
 */
interface IdentitySystemInterface
{
    /**
     * Return the process effective group ID.
     */
    public function effectiveGid(): int;

    /**
     * Return the process effective user ID.
     */
    public function effectiveUid(): int;

    /**
     * Initialize supplementary groups for the target identity.
     */
    public function initGroups(string $username, int $gid): bool;

    /**
     * Resolve one passwd entry by user ID.
     *
     * @return array<string, mixed>|false
     */
    public function passwd(int $uid): array|false;

    /**
     * Set the process primary group ID.
     */
    public function setGid(int $gid): bool;

    /**
     * Set the process user ID.
     */
    public function setUid(int $uid): bool;
}
