<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Internal\Http2ClientSession;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameParser;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Decoder;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;
use Infocyph\Runwire\Http\Http2\Http2Connection;

it('sends a single verified H2 request after peer SETTINGS and returns bounded DATA credit', function (): void {
    $responses = [];
    $body = '';
    $session = new Http2ClientSession(
        new ClientRequest('GET', 'https://example.test:8443/api/v1?q=1'),
        static function (int $status, Headers $headers) use (&$responses): void {
            $responses[] = [$status, $headers->first('content-type')];
        },
        static function (string $part) use (&$body): void { $body .= $part; },
    );
    $preface = $session->start();
    expect(str_starts_with($preface, Http2Connection::CLIENT_PREFACE))->toBeTrue();
    expect(fn() => $session->start())->toThrow(RuntimeException::class, 'already started');
    $wire = $session->receive(FrameWriter::settings());
    $frames = (new FrameParser())->push($wire);
    expect($frames)->toHaveCount(2)
        ->and($frames[0]->knownType())->toBe(FrameType::SETTINGS)
        ->and($frames[0]->hasFlag(0x1))->toBeTrue()
        ->and($frames[1]->knownType())->toBe(FrameType::HEADERS)
        ->and($frames[1]->streamId)->toBe(1);
    expect((new Decoder())->decode($frames[1]->payload))->toBe([
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', 'example.test:8443'],
        [':path', '/api/v1?q=1'],
    ]);

    $responseBlock = (new Encoder())->encode([[':status', '200'], ['content-type', 'text/plain']]);
    $session->receive(new Frame(FrameType::HEADERS->value, 0x4, 1, $responseBlock));
    expect($responses)->toBe([[200, 'text/plain']]);
    $credit = (new FrameParser())->push($session->receive(new Frame(FrameType::DATA->value, 0, 1, 'hello')));
    expect($credit)->toHaveCount(2)
        ->and($credit[0]->knownType())->toBe(FrameType::WINDOW_UPDATE)
        ->and($credit[0]->streamId)->toBe(0)
        ->and($credit[1]->streamId)->toBe(1)
        ->and($body)->toBe('hello');
    expect($session->receive(new Frame(FrameType::DATA->value, 0x1, 1, 'done')))->toBe('');
    expect($session->done())->toBeTrue()
        ->and($body)->toBe('hellodone');
    expect(fn() => $session->receive(FrameWriter::settings()))
        ->toThrow(RuntimeException::class, 'not accepting');
});

it('fails closed on pre-SETTINGS response, GOAWAY, non-H2 request and custom headers', function (): void {
    $onHeaders = static function (): void {};
    $onBody = static function (): void {};
    expect(fn() => new Http2ClientSession(
        new ClientRequest('POST', 'https://example.test/'),
        $onHeaders,
        $onBody,
    ))->toThrow(InvalidArgumentException::class, 'bodyless');
    $withCredentials = new Http2ClientSession(
        new ClientRequest('GET', 'https://example.test/', headers: Headers::fromArray(['authorization' => 'secret'])),
        $onHeaders,
        $onBody,
    );
    $withCredentials->start();
    $withCredentialsFrames = (new FrameParser())->push($withCredentials->receive(FrameWriter::settings()));
    expect((new Decoder())->decode($withCredentialsFrames[1]->payload))->toContain(['authorization', 'secret']);

    $withHopByHop = new Http2ClientSession(
        new ClientRequest('GET', 'https://example.test/', headers: Headers::fromArray(['connection' => 'close'])),
        $onHeaders,
        $onBody,
    );
    $withHopByHop->start();
    expect(fn() => $withHopByHop->receive(FrameWriter::settings()))
        ->toThrow(RuntimeException::class, 'forbidden connection-specific');

    $session = new Http2ClientSession(new ClientRequest('HEAD', 'https://example.test/'), $onHeaders, $onBody);
    expect(fn() => $session->receive(FrameWriter::settings()))
        ->toThrow(RuntimeException::class, 'not accepting');
    $session->start();
    expect(fn() => $session->receive(new Frame(FrameType::HEADERS->value, 0x5, 1)))
        ->toThrow(RuntimeException::class, 'unexpected stream');
    expect(fn() => $session->receive(FrameWriter::goAway(0, \Infocyph\Runwire\Http\Http2\Enum\ErrorCode::NO_ERROR)))
        ->toThrow(RuntimeException::class, 'first frame');
    $session->receive(FrameWriter::settings());
    expect(fn() => $session->receive(new Frame(FrameType::DATA->value, 0x1, 3, 'foreign')))
        ->toThrow(RuntimeException::class, 'unexpected stream');
    expect(fn() => $session->receive(FrameWriter::goAway(1, \Infocyph\Runwire\Http\Http2\Enum\ErrorCode::NO_ERROR)))
        ->toThrow(RuntimeException::class, 'before END_STREAM');
});

it('never publishes HEAD response DATA bytes even with a valid H2 HEADERS block', function (): void {
    $seen = [];
    $body = '';
    $session = new Http2ClientSession(
        new ClientRequest('HEAD', 'https://example.test/metadata'),
        static function (int $status) use (&$seen): void {
            $seen[] = $status;
        },
        static function (string $chunk) use (&$body): void {
            $body .= $chunk;
        },
    );
    $session->start();
    $session->receive(FrameWriter::settings());
    $block = (new Encoder())->encode([[':status', '200']]);
    $session->receive(new Frame(FrameType::HEADERS->value, 0x4, 1, $block));
    expect(fn() => $session->receive(new Frame(FrameType::DATA->value, 0x1, 1, 'private')))
        ->toThrow(RuntimeException::class, 'HEAD response must not contain body bytes')
        ->and($body)->toBe('')
        ->and($seen)->toBe([200]);
});

it('keeps H2 HEAD Content-Length metadata independent from body receipt', function (): void {
    $headers = [];
    $session = new Http2ClientSession(new ClientRequest('HEAD', 'https://example.test/metadata'),
        static function ($status, $fields) use (&$headers): void {
            expect($status)->toBe(200);
            $headers[] = $fields->first('content-length');
        },
        static function (): void {},
    );
    $session->start();
    $session->receive(FrameWriter::settings());
    $block = (new Encoder())->encode([[':status', '200'], ['content-length', '123']]);
    $session->receive(new Frame(FrameType::HEADERS->value, 0x5, 1, $block));
    expect($session->done())->toBeTrue()->and($headers)->toBe(['123']);
});
