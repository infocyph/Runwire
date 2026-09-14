<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Support;

use Infocyph\Runwire\Supervisor\Internal\IdentitySystemInterface;

/**
 * Deterministic identity-system fixture for privilege-drop acceptance tests.
 */
final class TestIdentitySystem implements IdentitySystemInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, mixed>|false */
    public array|false $passwdEntry = ['name' => 'runwire', 'gid' => 1001];

    public bool $initGroupsResult = true;
    public bool $setGidResult = true;
    public bool $setUidResult = true;
    public bool $applyGid = true;
    public bool $applyUid = true;

    public function __construct(
        public int $uid = 0,
        public int $gid = 0,
    ) {}

    public function effectiveUid(): int
    {
        return $this->uid;
    }

    public function effectiveGid(): int
    {
        return $this->gid;
    }

    public function passwd(int $uid): array|false
    {
        $this->calls[] = 'passwd:' . $uid;

        return $this->passwdEntry;
    }

    public function initGroups(string $username, int $gid): bool
    {
        $this->calls[] = sprintf('initgroups:%s:%d', $username, $gid);

        return $this->initGroupsResult;
    }

    public function setGid(int $gid): bool
    {
        $this->calls[] = 'setgid:' . $gid;
        if ($this->setGidResult && $this->applyGid) {
            $this->gid = $gid;
        }

        return $this->setGidResult;
    }

    public function setUid(int $uid): bool
    {
        $this->calls[] = 'setuid:' . $uid;
        if ($this->setUidResult && $this->applyUid) {
            $this->uid = $uid;
        }

        return $this->setUidResult;
    }
}
