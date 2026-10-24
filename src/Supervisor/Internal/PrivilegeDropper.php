<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Exception\SupervisorException;

/**
 * Applies one worker identity transition with supplementary-group normalization.
 *
 * @internal
 */
final readonly class PrivilegeDropper
{
    /**
     * Create a worker privilege-drop operation backed by the supplied identity system.
     */
    public function __construct(
        private IdentitySystemInterface $system,
    ) {}

    /**
     * Apply the requested worker UID/GID transition and verify the resulting identity.
     */
    public function apply(?int $uid, ?int $gid): void
    {
        if ($uid === null && $gid === null) {
            return;
        }

        $currentUid = $this->system->effectiveUid();
        $currentGid = $this->system->effectiveGid();
        [$username, $targetGid] = $this->resolveTargetIdentity($uid, $gid);

        if ($this->matchesTarget($currentUid, $currentGid, $uid, $targetGid)) {
            return;
        }

        $this->assertTransitionAllowed($currentUid);
        $this->initializeSupplementaryGroups($uid, $username, $targetGid);
        $this->applyPrimaryGid($currentGid, $targetGid);
        $this->applyUid($currentUid, $uid);
        $this->verifyIdentity($uid, $targetGid);
    }

    private function applyPrimaryGid(int $currentGid, ?int $targetGid): void
    {
        if ($targetGid === null || $currentGid === $targetGid) {
            return;
        }

        if (!$this->system->setGid($targetGid)) {
            throw new SupervisorException(sprintf('Unable to set worker GID to %d.', $targetGid));
        }
    }

    private function applyUid(int $currentUid, ?int $targetUid): void
    {
        if ($targetUid === null || $currentUid === $targetUid) {
            return;
        }

        if (!$this->system->setUid($targetUid)) {
            throw new SupervisorException(sprintf('Unable to set worker UID to %d.', $targetUid));
        }
    }

    private function assertTransitionAllowed(int $currentUid): void
    {
        if ($currentUid !== 0) {
            throw new SupervisorException('Changing worker identity requires the native master to have sufficient permissions.');
        }
    }

    private function initializeSupplementaryGroups(?int $uid, ?string $username, ?int $targetGid): void
    {
        if ($uid === null) {
            return;
        }

        if ($username === null || $targetGid === null || !$this->system->initGroups($username, $targetGid)) {
            throw new SupervisorException(sprintf('Unable to initialize supplementary groups for worker UID %d.', $uid));
        }
    }

    private function matchesTarget(int $currentUid, int $currentGid, ?int $targetUid, ?int $targetGid): bool
    {
        $uidMatches = $targetUid === null || $currentUid === $targetUid;
        $gidMatches = $targetGid === null || $currentGid === $targetGid;

        return $uidMatches && $gidMatches;
    }

    /** @return array{name: non-empty-string, gid: int} */
    private function resolvedPasswd(int $uid): array
    {
        $passwd = $this->system->passwd($uid);
        if ($passwd === false) {
            throw new SupervisorException(sprintf('Unable to resolve worker UID %d.', $uid));
        }

        $name = $passwd['name'] ?? null;
        $gid = $passwd['gid'] ?? null;
        if (!is_string($name) || $name === '' || !is_int($gid) || $gid < 0) {
            throw new SupervisorException(sprintf('Worker UID %d resolved to an invalid passwd entry.', $uid));
        }

        return ['name' => $name, 'gid' => $gid];
    }

    /** @return array{0: ?string, 1: ?int} */
    private function resolveTargetIdentity(?int $uid, ?int $gid): array
    {
        if ($uid === null) {
            return [null, $gid];
        }

        $passwd = $this->resolvedPasswd($uid);

        return [$passwd['name'], $gid ?? $passwd['gid']];
    }

    private function verifyIdentity(?int $uid, ?int $gid): void
    {
        if ($uid !== null && $this->system->effectiveUid() !== $uid) {
            throw new SupervisorException(sprintf('Worker UID verification failed after transition to %d.', $uid));
        }
        if ($gid !== null && $this->system->effectiveGid() !== $gid) {
            throw new SupervisorException(sprintf('Worker GID verification failed after transition to %d.', $gid));
        }
    }
}
