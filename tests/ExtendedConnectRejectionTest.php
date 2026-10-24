<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Internal\HeaderValidationException;
use Infocyph\Runwire\Http\Internal\RequestHeaderValidator;

it('rejects unsupported HTTP2 HTTP3 WebSocket and WebTransport extended CONNECT', function (): void {
    foreach (['HTTP/2', 'HTTP/3'] as $version) {
        $validator = new RequestHeaderValidator($version);
        foreach (['websocket', 'webtransport'] as $protocol) {
            $fields = [
                [':method', 'CONNECT'],
                [':scheme', 'https'],
                [':authority', 'example.test'],
                [':path', '/session'],
                [':protocol', $protocol],
            ];
            expect(fn() => $validator->request($fields))
                ->toThrow(HeaderValidationException::class);
        }
    }
});

it('retains ordinary CONNECT without promising extended CONNECT support', function (): void {
    foreach (['HTTP/2', 'HTTP/3'] as $version) {
        $head = new RequestHeaderValidator($version)->request([
            [':method', 'CONNECT'],
            [':authority', 'example.test:443'],
        ]);
        expect($head->method)->toBe('CONNECT')
            ->and($head->target)->toBe('example.test:443');
    }
});
