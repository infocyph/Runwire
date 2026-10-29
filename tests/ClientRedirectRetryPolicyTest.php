<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRedirectRetryPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;

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
    expect($next->uri)->toBe('https://two.example/new')
        ->and($next->method)->toBe('GET')
        ->and($next->headers->has('authorization'))->toBeFalse()
        ->and($next->headers->has('cookie'))->toBeFalse()
        ->and($next->headers->has('proxy-authorization'))->toBeFalse()
        ->and($next->headers->has('x-tenant'))->toBeFalse()
        ->and($next->headers->has('host'))->toBeFalse()
        ->and($next->headers->first('accept'))->toBe('application/json')
        ->and($next->headers->first('user-agent'))->toBe('runwire-test');
});

it('keeps same-origin credentials while forbidding downgrade, extra hop, unsafe replay and ambiguous targets', function (): void {
    $policy = new ClientRedirectRetryPolicy(maxRedirects: 1);
    $request = new ClientRequest(
        'GET',
        'https://one.example/a/base',
        Headers::fromArray(['authorization' => 'Bearer same-origin', 'cookie' => 'sid=1']),
    );
    $same = $policy->redirect($request, 'next', 0);
    expect($same->uri)->toBe('https://one.example/a/next')
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
