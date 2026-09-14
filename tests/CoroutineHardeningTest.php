<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Coroutine\CoroutinePolicy;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Exception\CoroutineOverflowException;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

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

it('keeps cancellation observers registered when unsubscribe handles are discarded', function (): void {
    $source = new CancellationSource();
    $called = 0;
    $source->token()->onCancel(static function () use (&$called): void {
        ++$called;
    });

    gc_collect_cycles();

    expect($source->token()->subscriptionCount())->toBe(1)
        ->and($source->cancel(CancellationReason::HOST_CANCELLED))->toBeTrue()
        ->and($called)->toBe(1)
        ->and($source->token()->subscriptionCount())->toBe(0);
});
