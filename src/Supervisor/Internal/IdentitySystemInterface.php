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
    public function effectiveUid(): int;

    public function effectiveGid(): int;

    /** @return array<string, mixed>|false */
    public function passwd(int $uid): array|false;

    public function initGroups(string $username, int $gid): bool;

    public function setGid(int $gid): bool;

    public function setUid(int $uid): bool;
}
