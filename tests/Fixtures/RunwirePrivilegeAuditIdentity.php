<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Fixtures;

use Infocyph\Runwire\Supervisor\Internal\IdentitySystemInterface;

final class RunwirePrivilegeAuditIdentity implements IdentitySystemInterface
{
    /** @var list<string> */
    public array $calls = [];

    public int $uid = 0;

    public int $gid = 0;

    public bool $allowGroups = true;

    public bool $allowGid = true;

    public bool $allowUid = true;

    public bool $retainOldUid = false;

    #[\Override]
    public function effectiveGid(): int
    {
        return $this->gid;
    }

    #[\Override]
    public function effectiveUid(): int
    {
        return $this->uid;
    }

    #[\Override]
    public function initGroups(string $username, int $gid): bool
    {
        $this->calls[] = 'groups:' . $username . ':' . $gid;

        return $this->allowGroups;
    }

    #[\Override]
    public function passwd(int $uid): array|false
    {
        $this->calls[] = 'passwd:' . $uid;

        return $uid === 1500 ? ['name' => 'runwire', 'gid' => 1600] : false;
    }

    #[\Override]
    public function setGid(int $gid): bool
    {
        $this->calls[] = 'gid:' . $gid;
        if (!$this->allowGid) {
            return false;
        }

        $this->gid = $gid;

        return true;
    }

    #[\Override]
    public function setUid(int $uid): bool
    {
        $this->calls[] = 'uid:' . $uid;
        if (!$this->allowUid) {
            return false;
        }
        if (!$this->retainOldUid) {
            $this->uid = $uid;
        }

        return true;
    }
}
