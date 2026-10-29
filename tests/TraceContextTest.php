<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Tracing\TraceContext;

it('roundtrips W3C traceparent and bounded tracestate without global state', function (): void {
    $trace = new TraceContext(str_repeat('a', 32), str_repeat('b', 16), 1, 'vendor=one');
    $headers = $trace->inject(Headers::fromArray(['accept' => 'application/json']));
    expect($headers->first('traceparent'))->toBe('00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01')
        ->and($headers->first('accept'))->toBe('application/json')
        ->and(TraceContext::fromHeaders($headers)?->traceState)->toBe('vendor=one');

    $child = $trace->child();
    expect($child->traceId)->toBe($trace->traceId)
        ->and($child->spanId)->not->toBe($trace->spanId)
        ->and($child->flags)->toBe(1);
});

it('rejects spoofed, duplicated and oversized W3C forwarding context', function (): void {
    $trace = new TraceContext(str_repeat('1', 32), str_repeat('2', 16));
    $header = $trace->inject(new Headers());
    foreach ([
        Headers::fromArray(['traceparent' => [$header->first('traceparent'), $header->first('traceparent')]]),
        Headers::fromArray(['traceparent' => '00-' . str_repeat('0', 32) . '-' . str_repeat('2', 16) . '-01']),
        Headers::fromArray(['traceparent' => '00-' . str_repeat('1', 32) . '-' . str_repeat('0', 16) . '-01']),
        Headers::fromArray(['traceparent' => 'ff-' . str_repeat('1', 32) . '-' . str_repeat('2', 16) . '-01']),
    ] as $bad) {
        expect(fn() => TraceContext::fromHeaders($bad))->toThrow(InvalidArgumentException::class);
    }
    expect(fn() => new TraceContext(str_repeat('1', 32), str_repeat('2', 16), traceState: str_repeat('a', 513)))
        ->toThrow(InvalidArgumentException::class);
});

it('uses request-owned trace context and clears it on completed request', function (): void {
    $request = RequestContext::standalone();
    $trace = new TraceContext(str_repeat('a', 32), str_repeat('b', 16));
    $trace->bind($request);
    expect(TraceContext::forRequest($request))->toBe($trace);
    $request->complete();
    expect(TraceContext::forRequest($request))->toBeNull()
        ->and(fn() => $trace->bind($request))->toThrow(LogicException::class);
});

it('replaces untrusted inbound identity when injecting the trusted trace', function (): void {
    $trace = new TraceContext(str_repeat('a', 32), str_repeat('b', 16));
    $untrusted = Headers::fromArray([
        'traceparent' => '00-' . str_repeat('1', 32) . '-' . str_repeat('2', 16) . '-ff',
        'tracestate' => 'evil=one', 'x-request-id' => 'safe',
    ]);
    $result = $trace->inject($untrusted);
    expect($result->all('traceparent'))->toHaveCount(1)
        ->and($result->all('tracestate'))->toBe([])
        ->and($result->first('x-request-id'))->toBe('safe');
});
