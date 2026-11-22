<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientDestinationPolicy;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRedirectRetryPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Enum\OutboundUriSource;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Interop\Psr\PsrMessageBridge;
use Nyholm\Psr7\Request;

it('strips all confidential and origin-specific headers on an explicitly allowed cross-origin redirect', function (): void {
    $policy = new ClientRedirectRetryPolicy(maxRedirects: 2, allowCrossOrigin: true);
    $request = new ClientRequest(
        'GET',
        'https://one.example:443/private',
        Headers::fromArray([
            'authorization' => 'Bearer top-secret',
            'cookie' => 'session=private',
            'proxy-authorization' => 'Basic secret',
            'x-tenant' => 'confidential',
            'host' => 'one.example',
            'accept' => 'application/json',
            'user-agent' => 'runwire-test',
        ]),
    );
    $next = $policy->redirect($request, 'https://two.example/new', 0);
    expect($request->uriSource)->toBe(OutboundUriSource::DIRECT)
        ->and($next->uri)->toBe('https://two.example/new')
        ->and($next->method)->toBe('GET')
        ->and($next->headers->has('authorization'))->toBeFalse()
        ->and($next->headers->has('cookie'))->toBeFalse()
        ->and($next->headers->has('proxy-authorization'))->toBeFalse()
        ->and($next->headers->has('x-tenant'))->toBeFalse()
        ->and($next->headers->has('host'))->toBeFalse()
        ->and($next->headers->first('accept'))->toBe('application/json')
        ->and($next->headers->first('user-agent'))->toBe('runwire-test');
});

it('requires a fresh destination policy match for each PSR redirect', function (): void {
    $initial = 'https://example.com/start';
    $next = 'https://example.com/next';
    $policy = new ClientProtocolPolicy(destinationPolicy: new ClientDestinationPolicy([$initial, $next]));
    $redirect = new ClientRedirectRetryPolicy(maxRedirects: 3);
    $psr = new Request('GET', $initial);
    $request = PsrMessageBridge::outboundRequest($psr, $policy);
    $first = $redirect->redirect($request, '/next', 0);
    $second = $redirect->redirect($first, '/different', 1);

    expect($first->uriForSend())->toBe($next)
        ->and($first->uriSource)->toBe(OutboundUriSource::REDIRECT);
    expect($second->uriForSend(...))->toThrow(InvalidArgumentException::class, 'not approved');

    $explicit = PsrMessageBridge::outboundRequest($psr, approvedUri: $initial);
    $withoutPolicy = $redirect->redirect($explicit, '/next', 0);
    expect($withoutPolicy->uriForSend(...))->toThrow(InvalidArgumentException::class, 'destination policy');
});

it('keeps same-origin credentials while forbidding downgrade, extra hop, unsafe replay and ambiguous targets', function (): void {
    $policy = new ClientRedirectRetryPolicy(maxRedirects: 1);
    $request = new ClientRequest(
        'GET',
        'https://one.example/a/base',
        Headers::fromArray(['authorization' => 'Bearer same-origin', 'cookie' => 'sid=1']),
        uriSource: OutboundUriSource::PSR_APPROVAL_MATCH,
    );
    $same = $policy->redirect($request, 'next', 0);
    expect($same->uri)->toBe('https://one.example/a/next')
        ->and($same->uriSource)->toBe(OutboundUriSource::REDIRECT)
        ->and($same->headers->first('authorization'))->toBe('Bearer same-origin')
        ->and($same->headers->first('cookie'))->toBe('sid=1');
    expect(fn() => $policy->redirect($request, 'http://one.example/unsafe', 0))
        ->toThrow(RuntimeException::class, 'plaintext');
    expect(fn() => $policy->redirect($request, 'https://other.example/private', 0))
        ->toThrow(RuntimeException::class, 'Cross-origin');
    expect(fn() => $policy->redirect($request, '/budget', 1))
        ->toThrow(RuntimeException::class, 'budget');
    expect(fn() => $policy->redirect($request, '//attacker.test', 0))
        ->toThrow(RuntimeException::class, 'malformed');
    expect(fn() => $policy->redirect($request, '/attack#fragment', 0))
        ->toThrow(RuntimeException::class, 'malformed');
    expect(fn() => $policy->redirect($request, 'https:\evil.example', 0))
        ->toThrow(RuntimeException::class);
    expect(fn() => $policy->redirect($request, 'file:private?next=https://example.test/continue', 0))
        ->toThrow(RuntimeException::class, 'unsupported protocol');
    expect(fn() => $policy->redirect($request, 'custom://evil.test?next=https://example.test/continue', 0))
        ->toThrow(RuntimeException::class, 'unsupported protocol');
    expect(fn() => $policy->redirect(
        new ClientRequest('POST', 'https://one.example/unsafe'),
        '/replay',
        0,
    ))->toThrow(RuntimeException::class, 'Unsafe');
});

it('retries only safe bodyless requests prior to visible response under an explicit budget', function (): void {
    $policy = new ClientRedirectRetryPolicy(maxRedirects: 0, maxRetries: 1);
    $safe = new ClientRequest(
        'HEAD',
        'http://localhost/status',
        protocol: new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true),
    );
    $post = new ClientRequest(
        'POST',
        'http://localhost/state',
        protocol: new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true),
    );
    expect($policy->shouldRetry($safe, 0, new RuntimeException('disconnected'), false))->toBeTrue()
        ->and($policy->shouldRetry($safe, 1, new RuntimeException('disconnected'), false))->toBeFalse()
        ->and($policy->shouldRetry($safe, 0, new RuntimeException('disconnected'), true))->toBeFalse()
        ->and($policy->shouldRetry($safe, 0, new InvalidArgumentException('bad config'), false))->toBeFalse()
        ->and($policy->shouldRetry($post, 0, new RuntimeException('disconnected'), false))->toBeFalse();
});

it('resolves query-only and dot-segment relative locations without changing query contents', function (): void {
    $redirect = new ClientRedirectRetryPolicy();
    $original = new ClientRequest('GET', 'https://example.test/a/b/item?old=1');

    foreach ([
        '?page=2' => 'https://example.test/a/b/item?page=2',
        '?next=https://example.test/continue' => 'https://example.test/a/b/item?next=https://example.test/continue',
        '?' => 'https://example.test/a/b/item?',
        '../next?x=/../y' => 'https://example.test/a/next?x=/../y',
        '../next?url=https://example.test/continue' => 'https://example.test/a/next?url=https://example.test/continue',
        './next' => 'https://example.test/a/b/next',
        '/a/./target/../ok' => 'https://example.test/a/ok',
        '/status?next=http://example.test/continue' => 'https://example.test/status?next=http://example.test/continue',
        '../../' => 'https://example.test/',
        '../../../q' => 'https://example.test/q',
        '/a//b/../c' => 'https://example.test/a//c',
        '.well-known' => 'https://example.test/a/b/.well-known',
    ] as $location => $expected) {
        expect($redirect->redirect($original, $location, 0)->uri)->toBe($expected);
    }

    $originWithoutPath = new ClientRequest('GET', 'https://example.test');
    expect($redirect->redirect($originWithoutPath, '?x=1', 0)->uri)
        ->toBe('https://example.test?x=1');
});

it('authorizes the resolved query-only redirect against the complete configured URI', function (): void {
    $from = 'https://example.test/a/item?old=1';
    $to = 'https://example.test/a/item?page=2';
    $nested = 'https://example.test/a/item?next=https://example.test/continue';
    $protocol = new ClientProtocolPolicy(destinationPolicy: new ClientDestinationPolicy([$from, $to, $nested]));
    $request = PsrMessageBridge::outboundRequest(new Request('GET', $from), $protocol);
    $redirect = new ClientRedirectRetryPolicy();

    expect($redirect->redirect($request, '?page=2', 0)->uriForSend())->toBe($to)
        ->and($redirect->redirect($request, '?next=https://example.test/continue', 0)->uriForSend())->toBe($nested)
        ->and(fn() => $redirect->redirect($request, '?next=https://attacker.test/continue', 0)->uriForSend())
        ->toThrow(InvalidArgumentException::class, 'not approved')
        ->and(fn() => $redirect->redirect($request, '?page=3', 0)->uriForSend())
        ->toThrow(InvalidArgumentException::class, 'not approved');
});
