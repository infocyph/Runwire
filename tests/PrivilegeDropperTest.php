<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\Exception\SupervisorException;
use Infocyph\Runwire\Supervisor\Internal\PrivilegeDropper;
use Infocyph\Runwire\Tests\Support\TestIdentitySystem;

it('normalizes supplementary groups before dropping gid and uid', function (): void {
    runwireOwnedPrivilegeDropperTestCase01();
});

/**
 * @throws \Throwable
 */
function runwireOwnedPrivilegeDropperTestCase01(): void
{
    $system = new TestIdentitySystem();

    (new PrivilegeDropper($system))->apply(1001, 2001);

    expect($system->calls)->toBe([
        'passwd:1001',
        'initgroups:runwire:2001',
        'setgid:2001',
        'setuid:1001',
    ])->and($system->uid)->toBe(1001)
        ->and($system->gid)->toBe(2001);
}

it('derives the base gid from passwd data for uid-only drops', function (): void {
    runwireOwnedPrivilegeDropperTestCase02();
});

/**
 * @throws \Throwable
 */
function runwireOwnedPrivilegeDropperTestCase02(): void
{
    $system = new TestIdentitySystem();

    (new PrivilegeDropper($system))->apply(1001, null);

    expect($system->calls)->toBe([
        'passwd:1001',
        'initgroups:runwire:1001',
        'setgid:1001',
        'setuid:1001',
    ])->and($system->gid)->toBe(1001);
}

it('fails when the target uid cannot be resolved', function (): void {
    runwireOwnedPrivilegeDropperTestCase03();
});

/**
 * @throws \Throwable
 */
function runwireOwnedPrivilegeDropperTestCase03(): void
{
    $system = new TestIdentitySystem();
    $system->passwdEntry = false;

    expect(fn() => (new PrivilegeDropper($system))->apply(1001, null))
        ->toThrow(SupervisorException::class, 'Unable to resolve worker UID 1001.');
}

it('fails when supplementary groups cannot be initialized', function (): void {
    runwireOwnedPrivilegeDropperTestCase04();
});

/**
 * @throws \Throwable
 */
function runwireOwnedPrivilegeDropperTestCase04(): void
{
    $system = new TestIdentitySystem();
    $system->initGroupsResult = false;

    expect(fn() => (new PrivilegeDropper($system))->apply(1001, 2001))
        ->toThrow(SupervisorException::class, 'Unable to initialize supplementary groups');

    expect($system->calls)->toBe([
        'passwd:1001',
        'initgroups:runwire:2001',
    ]);
}

it('fails gid transition before attempting uid transition', function (): void {
    runwireOwnedPrivilegeDropperTestCase05();
});

/**
 * @throws \Throwable
 */
function runwireOwnedPrivilegeDropperTestCase05(): void
{
    $system = new TestIdentitySystem();
    $system->setGidResult = false;

    expect(fn() => (new PrivilegeDropper($system))->apply(1001, 2001))
        ->toThrow(SupervisorException::class, 'Unable to set worker GID to 2001.');

    expect($system->calls)->toBe([
        'passwd:1001',
        'initgroups:runwire:2001',
        'setgid:2001',
    ]);
}

it('fails when uid transition is rejected', function (): void {
    runwireOwnedPrivilegeDropperTestCase06();
});

/**
 * @throws \Throwable
 */
function runwireOwnedPrivilegeDropperTestCase06(): void
{
    $system = new TestIdentitySystem();
    $system->setUidResult = false;

    expect(fn() => (new PrivilegeDropper($system))->apply(1001, 2001))
        ->toThrow(SupervisorException::class, 'Unable to set worker UID to 1001.');
}

it('fails when the final effective uid does not match the target', function (): void {
    runwireOwnedPrivilegeDropperTestCase07();
});

/**
 * @throws \Throwable
 */
function runwireOwnedPrivilegeDropperTestCase07(): void
{
    $system = new TestIdentitySystem();
    $system->applyUid = false;

    expect(fn() => (new PrivilegeDropper($system))->apply(1001, 2001))
        ->toThrow(SupervisorException::class, 'Worker UID verification failed after transition to 1001.');
}

it('fails when the final effective gid does not match the target', function (): void {
    runwireOwnedPrivilegeDropperTestCase08();
});

/**
 * @throws \Throwable
 */
function runwireOwnedPrivilegeDropperTestCase08(): void
{
    $system = new TestIdentitySystem();
    $system->applyGid = false;

    expect(fn() => (new PrivilegeDropper($system))->apply(null, 2001))
        ->toThrow(SupervisorException::class, 'Worker GID verification failed after transition to 2001.');
}

it('rejects identity changes from a non-root process', function (): void {
    runwireOwnedPrivilegeDropperTestCase09();
});

/**
 * @throws \Throwable
 */
function runwireOwnedPrivilegeDropperTestCase09(): void
{
    $system = new TestIdentitySystem(uid: 1000, gid: 1000);

    expect(fn() => (new PrivilegeDropper($system))->apply(1001, 1001))
        ->toThrow(SupervisorException::class, 'Changing worker identity requires');

    expect($system->calls)->toBe(['passwd:1001']);
}

it('does not mutate an already-running target identity', function (): void {
    runwireOwnedPrivilegeDropperTestCase10();
});

/**
 * @throws \Throwable
 */
function runwireOwnedPrivilegeDropperTestCase10(): void
{
    $system = new TestIdentitySystem(uid: 1001, gid: 1001);

    (new PrivilegeDropper($system))->apply(1001, null);

    expect($system->calls)->toBe(['passwd:1001']);
}
