<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\Internal\DialAttemptLifecycle;
use Infocyph\Runwire\Network\Internal\DialAttemptPhase;

it('requires connection verification before ownership transfer', function (): void {
    $plain = new DialAttemptLifecycle();
    expect($plain->readyForHandoff())->toBeFalse();
    $plain->transition(DialAttemptPhase::CONNECTING);
    expect(fn() => $plain->transition(DialAttemptPhase::HANDED_OFF))
        ->toThrow(LogicException::class);
    $plain->transition(DialAttemptPhase::READY);
    expect($plain->readyForHandoff())->toBeTrue();
    $plain->transition(DialAttemptPhase::HANDED_OFF);
    expect($plain->readyForHandoff())->toBeFalse()
        ->and($plain->phase())->toBe(DialAttemptPhase::HANDED_OFF);

    $tls = new DialAttemptLifecycle();
    $tls->transition(DialAttemptPhase::RESOLVING);
    $tls->transition(DialAttemptPhase::CONNECTING);
    $tls->transition(DialAttemptPhase::HANDSHAKING);
    expect(fn() => $tls->transition(DialAttemptPhase::HANDED_OFF))
        ->toThrow(LogicException::class);
    $tls->transition(DialAttemptPhase::READY);
    $tls->transition(DialAttemptPhase::HANDED_OFF);
    expect($tls->phase())->toBe(DialAttemptPhase::HANDED_OFF);
});

it('rejects every disallowed dial phase edge including resurrection and duplicate handoff', function (): void {
    $legal = [
        'new' => ['resolving', 'connecting', 'cancelled', 'failed'],
        'resolving' => ['connecting', 'cancelled', 'failed'],
        'connecting' => ['handshaking', 'ready', 'cancelled', 'failed'],
        'handshaking' => ['ready', 'cancelled', 'failed'],
        'ready' => ['handed_off', 'cancelled', 'failed'],
        'handed_off' => [],
        'cancelled' => [],
        'failed' => [],
    ];
    foreach (DialAttemptPhase::cases() as $source) {
        foreach (DialAttemptPhase::cases() as $target) {
            $attempt = new DialAttemptLifecycle();
            $path = match ($source) {
                DialAttemptPhase::NEW => [],
                DialAttemptPhase::RESOLVING => [DialAttemptPhase::RESOLVING],
                DialAttemptPhase::CONNECTING => [DialAttemptPhase::CONNECTING],
                DialAttemptPhase::HANDSHAKING => [DialAttemptPhase::CONNECTING, DialAttemptPhase::HANDSHAKING],
                DialAttemptPhase::READY => [DialAttemptPhase::CONNECTING, DialAttemptPhase::READY],
                DialAttemptPhase::HANDED_OFF => [DialAttemptPhase::CONNECTING, DialAttemptPhase::READY, DialAttemptPhase::HANDED_OFF],
                DialAttemptPhase::CANCELLED => [DialAttemptPhase::CANCELLED],
                DialAttemptPhase::FAILED => [DialAttemptPhase::FAILED],
            };
            foreach ($path as $phase) {
                $attempt->transition($phase);
            }
            if (in_array($target->value, $legal[$source->value], true)) {
                $attempt->transition($target);
                expect($attempt->phase())->toBe($target);
            } else {
                expect(fn() => $attempt->transition($target))
                    ->toThrow(LogicException::class);
                expect($attempt->phase())->toBe($source);
            }
        }
    }
});
