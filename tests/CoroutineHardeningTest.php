<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Coroutine\CoroutinePolicy;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Exception\CoroutineOverflowException;

it('cleans child cancellation ownership when task admission is rejected', function (): void {
    $runtime = new CoroutineRuntime(policy: new CoroutinePolicy(maxTasks: 1, maxReadyBacklog: 1));

    $subscriptionCount = $runtime->run(function (CoroutineScope $scope): int {
        for ($attempt = 0; $attempt < 128; ++$attempt) {
            try {
                $scope->spawn(static function (): void {});
                test()->fail('Expected child task admission to be rejected.');
            } catch (CoroutineOverflowException) {
                // The root task consumes the only task slot; rejected child sources must be disposed.
            }
        }

        return $scope->cancellation()->subscriptionCount();
    });

    expect($subscriptionCount)->toBe(0)
        ->and($runtime->activeTaskCount())->toBe(0);
});

it('unsubscribes abandoned cancellation observer handles defensively', function (): void {
    $source = new CancellationSource();
    $subscription = $source->token()->onCancel(static function (): void {});

    expect($source->token()->subscriptionCount())->toBe(1);

    unset($subscription);
    gc_collect_cycles();

    expect($source->token()->subscriptionCount())->toBe(0);
});
