<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\OutboundAddressPolicy;

it('blocks private loopback link-local reserved and IPv4-mapped destinations', function (): void {
    $policy = new OutboundAddressPolicy();
    foreach (['127.0.0.1', '10.1.1.1', '169.254.1.2', '192.168.0.1', '0.0.0.0', '::1', 'fe80::1', '::ffff:127.0.0.1'] as $ip) {
        expect(fn() => $policy->assertCandidate($ip))->toThrow(RuntimeException::class);
    }
    $policy->assertCandidate('8.8.8.8');
    $policy->assertCandidate('2606:4700:4700::1111');
    expect(true)->toBeTrue();
});

it('rejects rebinding between approved DNS candidate and actual connected peer', function (): void {
    $policy = new OutboundAddressPolicy();
    $policy->assertConnected('8.8.8.8', '8.8.8.8:443');
    $policy->assertConnected('2606:4700:4700::1111', '[2606:4700:4700::1111]:443');

    expect(fn() => $policy->assertConnected('8.8.8.8', '1.1.1.1:443'))->toThrow(RuntimeException::class)
        ->and(fn() => $policy->assertConnected('8.8.8.8', '127.0.0.1:443'))->toThrow(RuntimeException::class)
        ->and(fn() => $policy->assertConnected('8.8.8.8', 'example.com:443'))->toThrow(RuntimeException::class);
});

it('compares canonical kernel peers across IPv4 and IPv4-mapped IPv6 without allowing rebinding', function (): void {
    $policy = new OutboundAddressPolicy();

    $policy->assertCandidate('::ffff:8.8.8.8');
    $policy->assertConnected('8.8.8.8', '[::ffff:8.8.8.8]:443');
    $policy->assertConnected('::ffff:8.8.8.8', '8.8.8.8:443');

    expect(fn() => $policy->assertConnected('::ffff:8.8.8.8', '[::ffff:1.1.1.1]:443'))
        ->toThrow(RuntimeException::class)
        ->and(fn() => $policy->assertConnected('::ffff:8.8.8.8', '10.1.1.1:443'))
        ->toThrow(RuntimeException::class)
        ->and(fn() => $policy->assertConnected('8.8.8.8', '[::ffff:127.0.0.1]:443'))
        ->toThrow(RuntimeException::class);
});

it('denies unsafe direct and resolved dials before opening sockets or retaining watchers', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $dialer = new Dialer($loop);
    $racer = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    $opts = new DialOptions(addressPolicy: new OutboundAddressPolicy());
    $errors = [];

    try {
        expect(fn() => $dialer->connect('tcp://127.0.0.1:80', static function (): void {}, $opts))
            ->toThrow(RuntimeException::class)
            ->and($dialer->pendingCount())->toBe(0);

        $racer->connectResolved(['127.0.0.1'], 80, static function ($connection, $error) use (&$errors): void {
            $errors[] = [$connection, $error];
        }, $opts);

        expect($errors)->toHaveCount(1)
            ->and($errors[0][0])->toBeNull()
            ->and($errors[0][1])->toBeInstanceOf(RuntimeException::class)
            ->and($racer->pendingCount())->toBe(0)
            ->and($dialer->pendingCount())->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    } finally {
        $racer->close();
        $dialer->close();
        $executor->close();
    }
});
