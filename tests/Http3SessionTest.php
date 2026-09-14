<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Enum\StreamType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameWriter;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Http3ResponseWriter;
use Infocyph\Runwire\Http\Http3\Http3Session;
use Infocyph\Runwire\Http\Http3\Internal\ConnectionState;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder;
use Infocyph\Runwire\Http\Http3\VarIntCodec;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;

function http3SessionWriter(int $streamId, string $method): ResponseWriterInterface
{
    if ($streamId < 0) {
        throw new InvalidArgumentException('HTTP/3 stream id cannot be negative.');
    }

    return new Http3ResponseWriter(
        $method,
        static fn(): WriteResult => new WriteResult(WriteState::ACCEPTED, 0),
        static fn(): WriteResult => new WriteResult(WriteState::ACCEPTED, 0),
        static function (): void {},
        static function (): void {},
    );
}

function http3SessionDispatchedCount(Http3Session $session): int
{
    $property = new ReflectionProperty($session, 'dispatchedRequestStreams');

    return count($property->getValue($session));
}

it('dispatches a decoded HTTP/3 request through the version-neutral application contract once', function (): void {
    $requests = [];
    $writers = [];
    $state = new ConnectionState();
    $session = new Http3Session(
        $state,
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$requests, &$writers): void {
            $requests[] = $request;
            $writers[] = $writer;
        },
        http3SessionWriter(...),
        '203.0.113.10:44321',
        '192.0.2.10:443',
    );
    $encoder = new Encoder(0, 0);
    $headers = $encoder->encode([
        [':method', 'POST'],
        [':scheme', 'https'],
        [':authority', 'example.com'],
        [':path', '/submit'],
        ['content-length', '4'],
    ], 0)->block;

    $session->pushRequestStream(0, FrameWriter::encode(new Frame(FrameType::HEADERS->value, $headers)));
    $session->pushRequestStream(0, FrameWriter::encode(new Frame(FrameType::DATA->value, 'body')));
    $session->finishRequestStream(0);

    expect($requests)->toHaveCount(1)
        ->and($writers)->toHaveCount(1)
        ->and($requests[0]->method)->toBe('POST')
        ->and($requests[0]->target)->toBe('/submit')
        ->and($requests[0]->version)->toBe(ProtocolVersion::HTTP_3)
        ->and($requests[0]->peerAddress)->toBe('203.0.113.10:44321')
        ->and($requests[0]->localAddress)->toBe('192.0.2.10:443')
        ->and($requests[0]->encrypted)->toBeTrue()
        ->and($requests[0]->body->read())->toBe('body');
});

it('dispatches QPACK-blocked requests only after encoder instructions unblock them', function (): void {
    $dispatched = 0;
    $limits = new Http3Limits(qpackMaxTableCapacity: 220, qpackMaxBlockedStreams: 1);
    $session = new Http3Session(
        new ConnectionState($limits),
        static function () use (&$dispatched): void {
            ++$dispatched;
        },
        http3SessionWriter(...),
    );
    $peerEncoder = new Encoder(220, 1, dynamicTableCapacity: 220);
    $session->pushPeerUnidirectional(
        2,
        VarIntCodec::encode(StreamType::QPACK_ENCODER->value) . $peerEncoder->takeEncoderInstructions(),
    );
    $headers = $peerEncoder->encode([
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', 'example.com'],
        [':path', '/blocked'],
        ['x-dynamic', 'session'],
    ], 0);
    $instructions = $peerEncoder->takeEncoderInstructions();

    $session->pushRequestStream(0, FrameWriter::encode(new Frame(FrameType::HEADERS->value, $headers->block)));
    expect($dispatched)->toBe(0);

    $session->pushPeerUnidirectional(2, $instructions);

    expect($dispatched)->toBe(1);
});

it('drops pending dispatch state when a request stream is cancelled', function (): void {
    $dispatched = 0;
    $session = new Http3Session(
        new ConnectionState(),
        static function () use (&$dispatched): void {
            ++$dispatched;
        },
        http3SessionWriter(...),
    );

    $session->pushRequestStream(0, VarIntCodec::encode(FrameType::HEADERS->value));
    $session->cancelRequestStream(0);

    expect($dispatched)->toBe(0)
        ->and($session->state()->requestStream(0))->toBeNull()
        ->and(http3SessionDispatchedCount($session))->toBe(0);
});

it('releases dispatched stream bookkeeping after response processing', function (): void {
    $session = new Http3Session(
        new ConnectionState(),
        static function (): void {},
        http3SessionWriter(...),
    );
    $encoder = new Encoder(0, 0);
    $headers = $encoder->encode([
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', 'example.com'],
        [':path', '/'],
    ], 0)->block;

    $session->pushRequestStream(0, FrameWriter::encode(new Frame(FrameType::HEADERS->value, $headers)));
    expect(http3SessionDispatchedCount($session))->toBe(1);

    $session->releaseRequestStream(0);

    expect(http3SessionDispatchedCount($session))->toBe(0)
        ->and($session->state()->requestStream(0))->toBeNull();
});
