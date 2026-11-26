<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\Http\Client\Internal\Http2ClientHandshake;
use Infocyph\Runwire\Http\Http2\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameParser;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Decoder;
use Infocyph\Runwire\Http\Http2\Http2Connection;
use Infocyph\Runwire\Http\Http2\Internal\ConnectionError;
use Infocyph\Runwire\Http\Http2\PeerSettings;
use RuntimeException;

it('emits an exact H2 preface and validates independent server SETTINGS before HPACK request encoding', function (): void {
    runwireOwnedHttp2ClientHandshakeTestCase01();
});

/**
 * @throws \Throwable
 */
function runwireOwnedHttp2ClientHandshakeTestCase01(): void
{
    $handshake = new Http2ClientHandshake();
    $initial = $handshake->start();
    expect(str_starts_with($initial, Http2Connection::CLIENT_PREFACE))->toBeTrue()
        ->and($handshake->ready())->toBeFalse();
    $frame = (new FrameParser())->push(substr($initial, strlen(Http2Connection::CLIENT_PREFACE)));
    expect($frame)->toHaveCount(1)
        ->and($frame[0]->knownType())->toBe(FrameType::SETTINGS)
        ->and($frame[0]->streamId)->toBe(0);

    $ack = $handshake->receive(FrameWriter::settings([PeerSettings::HEADER_TABLE_SIZE => 128]));
    $ackFrames = (new FrameParser())->push($ack);
    expect($handshake->ready())->toBeTrue()
        ->and($ackFrames)->toHaveCount(1)
        ->and($ackFrames[0]->knownType())->toBe(FrameType::SETTINGS)
        ->and($ackFrames[0]->hasFlag(0x1))->toBeTrue()
        ->and($ackFrames[0]->payload)->toBe('');

    $requestFrames = (new FrameParser())->push($handshake->firstRequest('GET', 'https', 'example.test', '/first'));
    expect($requestFrames)->toHaveCount(1)
        ->and($requestFrames[0]->knownType())->toBe(FrameType::HEADERS)
        ->and($requestFrames[0]->hasFlag(0x5))->toBeTrue()
        ->and($requestFrames[0]->streamId)->toBe(1);
    expect(fn() => $handshake->firstRequest('GET', 'https', 'example.test', '/second'))
        ->toThrow(RuntimeException::class, 'verified');
    $headers = (new Decoder())->decode($requestFrames[0]->payload);
    expect($headers)->toBe([
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', 'example.test'],
        [':path', '/first'],
    ]);
}

it('fails closed on illegal H2 SETTINGS, first-frame, PUSH and GOAWAY state transitions', function (): void {
    runwireOwnedHttp2ClientHandshakeTestCase02();
});

/**
 * @throws \Throwable
 */
function runwireOwnedHttp2ClientHandshakeTestCase02(): void
{
    $notStarted = new Http2ClientHandshake();
    expect(fn() => $notStarted->receive(FrameWriter::settings()))
        ->toThrow(RuntimeException::class, 'not active');

    $handshake = new Http2ClientHandshake();
    $handshake->start();
    expect(fn() => $handshake->firstRequest('GET', 'https', 'example.test', '/'))
        ->toThrow(RuntimeException::class, 'verified');
    expect(fn() => $handshake->receive(new Frame(FrameType::PING->value, 0, 0, '12345678')))
        ->toThrow(RuntimeException::class, 'first frame');
    expect(fn() => $handshake->receive(FrameWriter::settings([PeerSettings::ENABLE_PUSH => 1])))
        ->toThrow(RuntimeException::class, 'ENABLE_PUSH');
    expect(fn() => $handshake->receive(new Frame(FrameType::SETTINGS->value, 0, 1, '')))
        ->toThrow(RuntimeException::class, 'stream zero');

    expect($handshake->receive(FrameWriter::settings()))->not->toBe('')
        ->and($handshake->ready())->toBeTrue();
    expect(fn() => $handshake->firstRequest('POST', 'https', 'example.test', '/'))
        ->toThrow(RuntimeException::class, 'bodyless');
    expect(fn() => $handshake->firstRequest('GET', 'http', 'example.test', '/'))
        ->toThrow(RuntimeException::class, 'HTTPS');
    expect(fn() => $handshake->firstRequest('GET', 'https', 'example.test', '/bad' . "\r\n"))
        ->toThrow(RuntimeException::class, 'verified');
    expect($handshake->receive(new Frame(FrameType::PING->value, 0, 0, '12345678')))
        ->toBe(FrameWriter::encode(new Frame(FrameType::PING->value, 0x1, 0, '12345678')));
    expect($handshake->receive(new Frame(FrameType::GOAWAY->value, 0, 0, pack('NN', 0, 0))))
        ->toBe('');
    expect($handshake->ready())->toBeFalse();
    expect(fn() => $handshake->firstRequest('GET', 'https', 'example.test', '/'))
        ->toThrow(RuntimeException::class, 'verified');
}

it('accepts legal connection credit and ignores bounded unknown H2 extension frames after SETTINGS', function (): void {
    runwireOwnedHttp2ClientHandshakeTestCase03();
});

/**
 * @throws \Throwable
 */
function runwireOwnedHttp2ClientHandshakeTestCase03(): void
{
    $h2 = new Http2ClientHandshake();
    $h2->start();
    expect(fn() => $h2->receive(FrameWriter::windowUpdate(0, 32)))
        ->toThrow(RuntimeException::class, 'first frame');
    $h2->receive(FrameWriter::settings());

    expect($h2->receive(FrameWriter::windowUpdate(0, 32_768)))->toBe('')
        ->and($h2->receive(new Frame(0xFA, 0, 0, 'unknown extension')))->toBe('')
        ->and($h2->ready())->toBeTrue();

    expect(fn() => $h2->receive(new Frame(FrameType::WINDOW_UPDATE->value, 0, 0, pack('N', 0))))
        ->toThrow(RuntimeException::class, 'cannot be zero');
    expect(fn() => $h2->receive(new Frame(FrameType::WINDOW_UPDATE->value, 0, 0, "\x01")))
        ->toThrow(RuntimeException::class, 'four bytes');
    expect(fn() => $h2->receive(new Frame(FrameType::WINDOW_UPDATE->value, 1, 0, pack('N', 1))))
        ->toThrow(RuntimeException::class, 'stream zero');
    expect(fn() => $h2->receive(new Frame(FrameType::WINDOW_UPDATE->value, 0, 1, pack('N', 1))))
        ->toThrow(RuntimeException::class, 'stream zero');
}

it('maintains connection-level HPACK and monotonic odd stream admission for future H2 multiplexing', function (): void {
    runwireOwnedHttp2ClientHandshakeTestCase04();
});

/**
 * @throws \Throwable
 */
function runwireOwnedHttp2ClientHandshakeTestCase04(): void
{
    $h2 = new Http2ClientHandshake();
    $h2->start();
    $h2->receive(FrameWriter::settings());
    $headers = \Infocyph\Runwire\Http\Headers::fromArray(['x-repeat' => 'same']);
    $first = (new FrameParser())->push($h2->requestOnStream(1, 'GET', 'https', 'example.test', '/a', $headers));
    $third = (new FrameParser())->push($h2->requestOnStream(3, 'GET', 'https', 'example.test', '/b', $headers));
    $decoder = new Decoder();

    expect($first)->toHaveCount(1)
        ->and($third)->toHaveCount(1)
        ->and($first[0]->streamId)->toBe(1)
        ->and($third[0]->streamId)->toBe(3)
        ->and($decoder->decode($first[0]->payload))->toContain([':path', '/a'], ['x-repeat', 'same'])
        ->and($decoder->decode($third[0]->payload))->toContain([':path', '/b'], ['x-repeat', 'same']);

    expect(fn() => $h2->requestOnStream(2, 'GET', 'https', 'example.test', '/'))
        ->toThrow(RuntimeException::class, 'verified')
        ->and(fn() => $h2->requestOnStream(3, 'GET', 'https', 'example.test', '/'))
        ->toThrow(RuntimeException::class, 'verified')
        ->and(fn() => $h2->requestOnStream(0x8000_0001, 'GET', 'https', 'example.test', '/'))
        ->toThrow(RuntimeException::class, 'verified');

    $h2->receive(FrameWriter::goAway(3, \Infocyph\Runwire\Http\Http2\Enum\ErrorCode::NO_ERROR));
    expect(fn() => $h2->requestOnStream(5, 'GET', 'https', 'example.test', '/'))
        ->toThrow(RuntimeException::class, 'verified');
}

it('records peer GOAWAY last-stream and error-code metadata without leaking debug text', function (): void {
    runwireOwnedHttp2ClientHandshakeTestCase05();
});

/**
 * @throws \Throwable
 */
function runwireOwnedHttp2ClientHandshakeTestCase05(): void
{
    $h2 = new Http2ClientHandshake();
    expect($h2->peerGoAwayLastStreamId())->toBeNull()
        ->and($h2->peerGoAwayErrorCode())->toBeNull();
    $h2->start();
    $h2->receive(FrameWriter::settings());
    $h2->receive(new Frame(FrameType::GOAWAY->value, 0, 0, pack('NN', 3, 11) . 'diagnostic-secret'));
    expect($h2->peerGoAwayLastStreamId())->toBe(3)
        ->and($h2->peerGoAwayErrorCode())->toBe(11)
        ->and($h2->ready())->toBeFalse();

    $hostile = new Http2ClientHandshake();
    $hostile->start();
    $hostile->receive(FrameWriter::settings());
    expect(fn() => $hostile->receive(new Frame(FrameType::GOAWAY->value, 0, 0, 'short')))
        ->toThrow(RuntimeException::class, 'last stream');
}

it('accepts omitted and zero server push settings including later updates and unknown flags', function (): void {
    $handshake = new Http2ClientHandshake();
    $handshake->start();
    $ack = FrameWriter::encode(FrameWriter::settings([], true));
    expect($handshake->receive(FrameWriter::settings()))->toBe($ack);
    $payload = pack('nNnNnN', PeerSettings::MAX_CONCURRENT_STREAMS, 4, PeerSettings::ENABLE_PUSH, 0, PeerSettings::ENABLE_PUSH, 0);
    expect($handshake->receive(new Frame(FrameType::SETTINGS->value, 0x80, 0, $payload)))->toBe($ack)
        ->and($handshake->peerMaxConcurrentStreams())->toBe(4)
        ->and($handshake->ready())->toBeTrue();

    $initial = new Http2ClientHandshake();
    $initial->start();
    expect($initial->receive(FrameWriter::settings([PeerSettings::ENABLE_PUSH => 0])))->toBe($ack)
        ->and($initial->firstRequest('HEAD', 'https', 'example.test', '/'))->not->toBe('');
});

it('rejects nonzero server push and malformed SETTINGS with the correct error code', function (): void {
    foreach ([
        [pack('nN', PeerSettings::ENABLE_PUSH, 1), 0, 0, ErrorCode::PROTOCOL_ERROR],
        [pack('nN', PeerSettings::ENABLE_PUSH, 2), 0, 0, ErrorCode::PROTOCOL_ERROR],
        [pack('nNnN', PeerSettings::ENABLE_PUSH, 1, PeerSettings::ENABLE_PUSH, 0), 0, 0, ErrorCode::PROTOCOL_ERROR],
        ["\x00", 0, 0, ErrorCode::FRAME_SIZE_ERROR],
        [pack('nN', PeerSettings::ENABLE_PUSH, 0), 0, 1, ErrorCode::PROTOCOL_ERROR],
    ] as [$payload, $flags, $stream, $expected]) {
        $handshake = new Http2ClientHandshake();
        $handshake->start();
        try {
            $handshake->receive(new Frame(FrameType::SETTINGS->value, $flags, $stream, $payload));
            \PHPUnit\Framework\Assert::fail('Invalid server SETTINGS accepted.');
        } catch (ConnectionError $error) {
            expect($error->errorCode)->toBe($expected)
                ->and($handshake->ready())->toBeFalse();
        }
    }

    $handshake = new Http2ClientHandshake();
    $handshake->start();
    $handshake->receive(FrameWriter::settings());
    try {
        $handshake->receive(new Frame(FrameType::SETTINGS->value, 1, 0, pack('nN', PeerSettings::ENABLE_PUSH, 0)));
        \PHPUnit\Framework\Assert::fail('Nonempty SETTINGS ACK accepted.');
    } catch (ConnectionError $error) {
        expect($error->errorCode)->toBe(ErrorCode::FRAME_SIZE_ERROR);
    }
});
