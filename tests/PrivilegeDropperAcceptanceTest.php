<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\SupervisorException;
use Infocyph\Runwire\Supervisor\Internal\IdentitySystemInterface;
use Infocyph\Runwire\Supervisor\Internal\PrivilegeDropper;

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

    public function effectiveGid(): int
    {
        return $this->gid;
    }

    public function effectiveUid(): int
    {
        return $this->uid;
    }

    public function initGroups(string $username, int $gid): bool
    {
        $this->calls[] = 'groups:' . $username . ':' . $gid;

        return $this->allowGroups;
    }

    public function passwd(int $uid): array|false
    {
        $this->calls[] = 'passwd:' . $uid;

        return $uid === 1500 ? ['name' => 'runwire', 'gid' => 1600] : false;
    }

    public function setGid(int $gid): bool
    {
        $this->calls[] = 'gid:' . $gid;
        if (!$this->allowGid) {
            return false;
        }

        $this->gid = $gid;

        return true;
    }

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

it('initializes groups then GID before UID and verifies the resulting identity', function (): void {
    $identity = new RunwirePrivilegeAuditIdentity();
    (new PrivilegeDropper($identity))->apply(1500, null);

    expect($identity->calls)->toBe([
        'passwd:1500', 'groups:runwire:1600', 'gid:1600', 'uid:1500',
    ])->and($identity->uid)->toBe(1500)
        ->and($identity->gid)->toBe(1600);
});

it('applies GID-only transitions without UID or supplementary-group changes', function (): void {
    $identity = new RunwirePrivilegeAuditIdentity();
    (new PrivilegeDropper($identity))->apply(null, 1600);

    expect($identity->calls)->toBe(['gid:1600'])
        ->and($identity->uid)->toBe(0)
        ->and($identity->gid)->toBe(1600);
});

it('rejects unprivileged identity changes before attempting UID or GID changes', function (): void {
    $identity = new RunwirePrivilegeAuditIdentity();
    $identity->uid = 1000;
    $identity->gid = 1000;

    expect(fn() => (new PrivilegeDropper($identity))->apply(1500, null))
        ->toThrow(SupervisorException::class, 'Changing worker identity requires');

    expect($identity->calls)->toBe(['passwd:1500']);
});

it('rejects supplementary-group initialization failures before setting UID or GID', function (): void {
    $identity = new RunwirePrivilegeAuditIdentity();
    $identity->allowGroups = false;

    expect(fn() => (new PrivilegeDropper($identity))->apply(1500, null))
        ->toThrow(SupervisorException::class, 'Unable to initialize supplementary groups');

    expect($identity->calls)->toBe(['passwd:1500', 'groups:runwire:1600']);
});

it('fails closed when either the GID or UID transition is rejected', function (): void {
    $groupFailure = new RunwirePrivilegeAuditIdentity();
    $groupFailure->allowGid = false;

    expect(fn() => (new PrivilegeDropper($groupFailure))->apply(1500, null))
        ->toThrow(SupervisorException::class, 'Unable to set worker GID');

    expect($groupFailure->calls)->toBe([
        'passwd:1500', 'groups:runwire:1600', 'gid:1600',
    ]);

    $userFailure = new RunwirePrivilegeAuditIdentity();
    $userFailure->allowUid = false;

    expect(fn() => (new PrivilegeDropper($userFailure))->apply(1500, null))
        ->toThrow(SupervisorException::class, 'Unable to set worker UID');

    expect($userFailure->calls)->toBe([
        'passwd:1500', 'groups:runwire:1600', 'gid:1600', 'uid:1500',
    ]);
});

it('verifies the effective UID after a transition that claims success', function (): void {
    $identity = new RunwirePrivilegeAuditIdentity();
    $identity->retainOldUid = true;

    expect(fn() => (new PrivilegeDropper($identity))->apply(1500, null))
        ->toThrow(SupervisorException::class, 'Worker UID verification failed');

    expect($identity->uid)->toBe(0)
        ->and($identity->gid)->toBe(1600);
});

it('does not mutate POSIX process identity when no transition is requested', function (): void {
    $identity = new RunwirePrivilegeAuditIdentity();
    (new PrivilegeDropper($identity))->apply(null, null);

    expect($identity->calls)->toBe([]);
});
