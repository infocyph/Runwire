<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http2\Internal\HeaderValidationException as Http2HeaderValidationException;
use Infocyph\Runwire\Http\Http2\Internal\RequestHeaderValidator as Http2RequestHeaderValidator;
use Infocyph\Runwire\Http\Internal\HeaderValidationException;
use Infocyph\Runwire\Http\Internal\RequestHeaderValidator;

it('normalizes authority through the shared HTTP/2 and HTTP/3 request rules', function (): void {
    $fields = [
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', 'Example.Test'],
        [':path', '/resource'],
    ];

    $http2 = new Http2RequestHeaderValidator()->request($fields);
    $http3 = new RequestHeaderValidator('HTTP/3')->request($fields);

    expect($http2->target)->toBe('/resource')
        ->and($http2->headers->first('host'))->toBe('Example.Test')
        ->and($http3->target)->toBe('/resource')
        ->and($http3->headers->first('host'))->toBe('Example.Test');
});

it('requires authority or Host for HTTP schemes in both protocols', function (): void {
    $fields = [
        [':method', 'GET'],
        [':scheme', 'https'],
        [':path', '/resource'],
    ];

    expect(fn () => new Http2RequestHeaderValidator()->request($fields))
        ->toThrow(Http2HeaderValidationException::class)
        ->and(fn () => new RequestHeaderValidator('HTTP/3')->request($fields))
        ->toThrow(HeaderValidationException::class);
});

it('rejects empty or userinfo authority values', function (): void {
    $validator = new RequestHeaderValidator('HTTP/3');
    $base = [
        [':method', 'GET'],
        [':scheme', 'https'],
        [':path', '/resource'],
    ];

    expect(fn () => $validator->request([...$base, [':authority', '']]))
        ->toThrow(HeaderValidationException::class)
        ->and(fn () => $validator->request([...$base, [':authority', 'user@example.test']]))
        ->toThrow(HeaderValidationException::class)
        ->and(fn () => $validator->request([...$base, ['host', '']]))
        ->toThrow(HeaderValidationException::class);
});

it('rejects malformed HTTP authority syntax in HTTP/2 and HTTP/3', function (string $authority): void {
    $fields = [
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', $authority],
        [':path', '/resource'],
    ];

    expect(fn() => new Http2RequestHeaderValidator()->request($fields))
        ->toThrow(Http2HeaderValidationException::class)
        ->and(fn() => new RequestHeaderValidator('HTTP/3')->request($fields))
        ->toThrow(HeaderValidationException::class);
})->with([
    'whitespace' => 'bad host',
    'non-numeric port' => 'example.test:garbage',
    'unterminated IPv6' => '[::1',
    'unbracketed IPv6' => '::1',
    'out-of-range port' => 'example.test:65536',
]);

it('canonicalizes default ports when comparing authority and Host', function (): void {
    $head = new RequestHeaderValidator('HTTP/3')->request([
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', 'Example.Test:443'],
        [':path', '/resource'],
        ['host', 'example.test'],
    ]);

    expect($head->headers->first('host'))->toBe('example.test');
});

it('keeps non-HTTP schemes valid while enforcing HTTP path rules', function (): void {
    $validator = new RequestHeaderValidator('HTTP/3');
    $custom = $validator->request([
        [':method', 'FETCH'],
        [':scheme', 'urn'],
        [':path', ''],
    ]);

    expect($custom->target)->toBe('')
        ->and(fn () => $validator->request([
            [':method', 'GET'],
            [':scheme', 'https'],
            [':authority', 'example.test'],
            [':path', 'relative'],
        ]))->toThrow(HeaderValidationException::class)
        ->and(fn () => $validator->request([
            [':method', 'GET'],
            [':scheme', 'https'],
            [':authority', 'example.test'],
            [':path', '*'],
        ]))->toThrow(HeaderValidationException::class);
});

it('validates method and scheme syntax while allowing OPTIONS asterisk-form', function (): void {
    $validator = new RequestHeaderValidator('HTTP/3');
    $options = $validator->request([
        [':method', 'OPTIONS'],
        [':scheme', 'https'],
        [':authority', 'example.test'],
        [':path', '*'],
    ]);

    expect($options->target)->toBe('*')
        ->and(fn () => $validator->request([
            [':method', "GET\n"],
            [':scheme', 'https'],
            [':authority', 'example.test'],
            [':path', '/'],
        ]))->toThrow(HeaderValidationException::class)
        ->and(fn () => $validator->request([
            [':method', 'GET'],
            [':scheme', '1https'],
            [':authority', 'example.test'],
            [':path', '/'],
        ]))->toThrow(HeaderValidationException::class);
});
