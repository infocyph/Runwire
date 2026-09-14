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
        $targetGid = $gid;
        $username = null;

        if ($uid !== null) {
            $passwd = $this->system->passwd($uid);
            if ($passwd === false || !isset($passwd['name'], $passwd['gid']) || !is_string($passwd['name'])) {
                throw new SupervisorException(sprintf('Unable to resolve worker UID %d.', $uid));
            }

            $username = $passwd['name'];
            $passwdGid = filter_var($passwd['gid'], FILTER_VALIDATE_INT);
            if ($username === '' || $passwdGid === false || $passwdGid < 0) {
                throw new SupervisorException(sprintf('Worker UID %d resolved to an invalid passwd entry.', $uid));
            }
            $targetGid ??= $passwdGid;
        }

        $uidChange = $uid !== null && $currentUid !== $uid;
        $gidChange = $targetGid !== null && $currentGid !== $targetGid;
        if (!$uidChange && !$gidChange) {
            return;
        }
        if ($currentUid !== 0) {
            throw new SupervisorException('Changing worker identity requires the native master to have sufficient permissions.');
        }

        if ($uid !== null) {
            if ($username === null || $targetGid === null || !$this->system->initGroups($username, $targetGid)) {
                throw new SupervisorException(sprintf('Unable to initialize supplementary groups for worker UID %d.', $uid));
            }
        }

        if ($targetGid !== null && $currentGid !== $targetGid && !$this->system->setGid($targetGid)) {
            throw new SupervisorException(sprintf('Unable to set worker GID to %d.', $targetGid));
        }
        if ($uid !== null && $currentUid !== $uid && !$this->system->setUid($uid)) {
            throw new SupervisorException(sprintf('Unable to set worker UID to %d.', $uid));
        }

        if ($uid !== null && $this->system->effectiveUid() !== $uid) {
            throw new SupervisorException(sprintf('Worker UID verification failed after transition to %d.', $uid));
        }
        if ($targetGid !== null && $this->system->effectiveGid() !== $targetGid) {
            throw new SupervisorException(sprintf('Worker GID verification failed after transition to %d.', $targetGid));
        }
    }
}
