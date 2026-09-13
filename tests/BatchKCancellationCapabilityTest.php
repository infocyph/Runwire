<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RuntimeCapabilityResolver;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;

it('unsubscribes cancellation observers deterministically', function (): void {
    $source = new CancellationSource();
    $calls = 0;
    $subscription = $source->token()->onCancel(static function () use (&$calls): void {
        ++$calls;
    });

    expect($subscription->active())->toBeTrue()
        ->and($subscription->unsubscribe())->toBeTrue()
        ->and($subscription->active())->toBeFalse()
        ->and($subscription->unsubscribe())->toBeFalse()
        ->and($source->cancel(CancellationReason::HOST_CANCELLED))->toBeTrue()
        ->and($calls)->toBe(0);
});

it('throws a reason-carrying cancellation checkpoint exception', function (): void {
    $source = new CancellationSource();
    $source->cancel(CancellationReason::WORKER_SHUTDOWN);

    try {
        $source->token()->throwIfCancelled();
        test()->fail('Expected cancellation checkpoint to throw.');
    } catch (CancelledException $exception) {
        expect($exception->reason)->toBe(CancellationReason::WORKER_SHUTDOWN);
    }
});

it('links child cancellation with the earliest deadline and deterministic unlinking', function (): void {
    $parent = new CancellationSource(new RequestDeadline(2_000));
    $child = $parent->child(new RequestDeadline(3_000));
    $detachedByCompletion = $parent->child(new RequestDeadline(1_500));

    expect($child->token()->deadline()->monotonicNanoseconds)->toBe(2_000)
        ->and($detachedByCompletion->token()->deadline()->monotonicNanoseconds)->toBe(1_500);

    $detachedByCompletion->dispose();
    $parent->cancel(CancellationReason::HOST_CANCELLED);

    expect($child->token()->isCancelled())->toBeTrue()
        ->and($child->token()->reason())->toBe(CancellationReason::HOST_CANCELLED)
        ->and($detachedByCompletion->token()->isCancelled())->toBeFalse();
});

it('separates host-native coroutine capability from Runwire coroutine readiness', function (): void {
    $resolver = new RuntimeCapabilityResolver();
    $native = $resolver->resolve(
        RuntimeDriver::NATIVE,
        new RuntimeEnvironment(sapi: 'cli', availableDrivers: [RuntimeDriver::NATIVE]),
    );
    $swoole = $resolver->resolve(
        RuntimeDriver::SWOOLE,
        new RuntimeEnvironment(sapi: 'cli', hostedDrivers: [RuntimeDriver::SWOOLE]),
    );

    expect($native->runwireLoopAvailable)->toBeTrue()
        ->and($native->supportsRunwireCoroutines)->toBeFalse()
        ->and($native->hostNativeCoroutines)->toBeFalse()
        ->and($native->supports(RuntimeCapability::CONCURRENT))->toBeFalse()
        ->and($native->supports(RuntimeCapability::RUNWIRE_LOOP_AVAILABLE))->toBeTrue()
        ->and($swoole->hostOwnsEventLoop)->toBeTrue()
        ->and($swoole->hostNativeCoroutines)->toBeTrue()
        ->and($swoole->supportsRunwireCoroutines)->toBeFalse()
        ->and($swoole->supports(RuntimeCapability::HOST_NATIVE_COROUTINES))->toBeTrue()
        ->and($swoole->supports(RuntimeCapability::CONCURRENT))->toBeFalse();
});
