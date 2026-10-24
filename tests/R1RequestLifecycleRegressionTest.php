<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Network\Enum\WriteState;

it('distinguishes a bounded null attribute from a missing key during updates', function (): void {
    $context = RequestContext::create(RuntimeContext::standalone(), maxAttributes: 1);
    $context->setAttribute('nullable', null);

    expect($context->hasAttribute('nullable'))->toBeTrue()
        ->and($context->attribute('nullable', 'fallback'))->toBeNull()
        ->and($context->attribute('missing', 'fallback'))->toBe('fallback');

    $context->setAttribute('nullable', 42);
    expect($context->attribute('nullable'))->toBe(42)
        ->and(fn() => $context->setAttribute('overflow', 1))->toThrow(OverflowException::class);

    $context->removeAttribute('nullable');
    $context->setAttribute('reused', null);
    $context->complete();
    expect($context->attributes())->toBe([])
        ->and($context->completed())->toBeTrue();
});

it('rejects invalid request identities without relying on mutable global state', function (): void {
    foreach (['', str_repeat('x', 129), "bad\nrequest"] as $id) {
        expect(fn() => RequestContext::standalone($id))->toThrow(InvalidArgumentException::class);
    }

    $context = RequestContext::standalone();
    expect(strlen($context->requestId))->toBe(32)
        ->and(ctype_xdigit($context->requestId))->toBeTrue();
    $context->complete();
});

it('cancels and completes a request once even under reentrant completion observers', function (): void {
    $context = RequestContext::standalone();
    $notifications = 0;
    $context->setAttribute('tenant', 'scoped');
    $context->observeCompletion(static function () use ($context, &$notifications): void {
        ++$notifications;
        expect($context->completed())->toBeTrue()
            ->and($context->attributes())->toBe([]);
        $context->complete();
    });

    expect($context->cancel(CancellationReason::HOST_CANCELLED))->toBeTrue()
        ->and($context->cancel(CancellationReason::HOST_CANCELLED))->toBeFalse();

    $context->complete();
    $context->complete();

    expect($notifications)->toBe(1)
        ->and($context->cancellation->isCancelled())->toBeTrue();
});

it('notifies terminal response ownership once under a reentrant double-close', function (): void {
    $endCalls = 0;
    $terminalCalls = 0;
    $writer = new CallbackResponseWriter(
        static function (): void {},
        static function (string $chunk): void { strlen($chunk); },
        static function () use (&$endCalls): void { ++$endCalls; },
        1_024,
    );
    $writer->onTerminal(static function (CallbackResponseWriter $terminal) use (&$terminalCalls): void {
        ++$terminalCalls;
        expect($terminal->end()->state)->toBe(WriteState::CLOSED);
    });

    expect($writer->end('ok')->state)->toBe(WriteState::ACCEPTED)
        ->and($writer->end()->state)->toBe(WriteState::CLOSED)
        ->and($writer->isEnded())->toBeTrue()
        ->and($endCalls)->toBe(1)
        ->and($terminalCalls)->toBe(1);

    $writer->onTerminal(static function (CallbackResponseWriter $terminal) use (&$terminalCalls): void {
        ++$terminalCalls;
        expect($terminal->isEnded())->toBeTrue();
    });
    expect($terminalCalls)->toBe(2);
});
