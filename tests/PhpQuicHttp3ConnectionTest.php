<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\ErrorCode;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameParser;
use Infocyph\Runwire\Http\Http3\FrameType;
use Infocyph\Runwire\Http\Http3\FrameWriter;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicConnection;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicEventMasks;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Connection;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;

function fakeHttp3ConnectionStream(int $id, bool $bidirectional, array $reads = [], int $maxWrite = PHP_INT_MAX): object
{
    return new class($id, $bidirectional, $reads, $maxWrite) {
        public bool $ended = false;

        public ?int $peerResetCode = null;

        public string $written = '';

        /** @param list<string|null> $reads */
        public function __construct(
            private readonly int $id,
            private readonly bool $bidirectional,
            private array $reads,
            private readonly int $maxWrite,
        ) {}

        public function end(): void
        {
            $this->ended = true;
        }

        public function getId(): int
        {
            return $this->id;
        }

        public function getResetCode(): ?int
        {
            return $this->peerResetCode;
        }

        public function isBidirectional(): bool
        {
            return $this->bidirectional;
        }

        public function read(int $length): ?string
        {
            $next = array_shift($this->reads);
            if ($next === null || $next === '') {
                return $next;
            }
            if (strlen($next) <= $length) {
                return $next;
            }

            $chunk = substr($next, 0, $length);
            array_unshift($this->reads, substr($next, $length));

            return $chunk;
        }

        public function reset(int $errorCode = 0): void
        {
            $this->peerResetCode = $errorCode;
        }

        public function write(string $data, bool $fin = false): int
        {
            $written = min(strlen($data), $this->maxWrite);
            $this->written .= substr($data, 0, $written);
            if ($fin) {
                $this->ended = true;
            }

            return $written;
        }
    };
}

function fakeHttp3ConnectionRaw(array $localStreams, array $acceptedStreams, string $alpn = 'h3'): object
{
    return new class($localStreams, $acceptedStreams, $alpn) {
        public bool $blocking = true;

        /** @var list<array{0: int, 1: string, 2: bool}> */
        public array $closed = [];

        /** @param list<object> $localStreams @param list<object> $acceptedStreams */
        public function __construct(
            private array $localStreams,
            public array $acceptedStreams,
            private readonly string $alpn,
        ) {}

        public function acceptStream(): ?object
        {
            return array_shift($this->acceptedStreams);
        }

        public function close(int $errorCode = 0, string $reason = '', bool $rapid = false): void
        {
            $this->closed[] = [$errorCode, $reason, $rapid];
        }

        public function getNegotiatedAlpn(): ?string
        {
            return $this->alpn;
        }

        public function openStream(bool $bidirectional = true): object
        {
            $stream = array_shift($this->localStreams);
            if (!is_object($stream) || $stream->isBidirectional() !== $bidirectional) {
                throw new RuntimeException('Invalid local test QUIC stream.');
            }

            return $stream;
        }

        public function setBlocking(bool $blocking): void
        {
            $this->blocking = $blocking;
        }
    };
}

it('pumps a client request through the shared HTTP contract and flushes the HTTP/3 response', function (): void {
    $encoder = new Encoder(0, 0);
    $headerBlock = $encoder->encode([
        [':method', 'GET'],
        [':scheme', 'https'],
        [':authority', 'example.com'],
        [':path', '/hello'],
    ], 0)->block;
    $requestRaw = fakeHttp3ConnectionStream(0, true, [
        FrameWriter::encode(new Frame(FrameType::HEADERS->value, $headerBlock)),
        null,
    ]);
    $controlRaw = fakeHttp3ConnectionStream(3, false);
    $encoderRaw = fakeHttp3ConnectionStream(7, false);
    $decoderRaw = fakeHttp3ConnectionStream(11, false);
    $connectionRaw = fakeHttp3ConnectionRaw([$controlRaw, $encoderRaw, $decoderRaw], [$requestRaw]);
    $requests = [];
    $connection = new PhpQuicHttp3Connection(
        new PhpQuicConnection($connectionRaw),
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$requests): void {
            $requests[] = $request;
            $writer->end('ok');
        },
    );

    $connection->pump();

    $frames = new FrameParser((new Http3Limits())->maxFramePayloadBytes)->push($requestRaw->written);
    expect($connectionRaw->blocking)->toBeFalse()
        ->and($requests)->toHaveCount(1)
        ->and($requests[0]->target)->toBe('/hello')
        ->and($frames)->toHaveCount(2)
        ->and($frames[0]->knownType())->toBe(FrameType::HEADERS)
        ->and($frames[1]->knownType())->toBe(FrameType::DATA)
        ->and($frames[1]->payload)->toBe('ok')
        ->and($requestRaw->ended)->toBeTrue()
        ->and($connection->activeRequestStreams())->toBe(0)
        ->and($controlRaw->written)->not->toBe('')
        ->and($encoderRaw->written)->not->toBe('')
        ->and($decoderRaw->written)->not->toBe('');
});

it('builds an injectable QUIC poll set and handles only ready request streams', function (): void {
    $events = new PhpQuicEventMasks(1, 2, 4, 8, 16);
    $encoder = new Encoder(0, 0);
    $requestRaw = fakeHttp3ConnectionStream(0, true, [
        FrameWriter::encode(new Frame(FrameType::HEADERS->value, $encoder->encode([
            [':method', 'GET'],
            [':scheme', 'https'],
            [':authority', 'example.com'],
            [':path', '/ready'],
        ], 0)->block)),
        null,
    ]);
    $connectionRaw = fakeHttp3ConnectionRaw(
        [
            fakeHttp3ConnectionStream(3, false),
            fakeHttp3ConnectionStream(7, false),
            fakeHttp3ConnectionStream(11, false),
        ],
        [$requestRaw],
    );
    $requests = [];
    $connection = new PhpQuicHttp3Connection(
        new PhpQuicConnection($connectionRaw),
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$requests): void {
            $requests[] = $request;
            $writer->end('ready');
        },
    );

    $initial = $connection->pollItems($events);
    expect($initial[spl_object_id($connectionRaw)][1] & $events->acceptStream)->not->toBe(0);

    $connection->handleReady([spl_object_id($connectionRaw) => $events->acceptStream], $events);
    $withRequest = $connection->pollItems($events);
    expect($withRequest[spl_object_id($requestRaw)][1] & $events->read)->not->toBe(0);

    $connection->handleReady([spl_object_id($requestRaw) => $events->read], $events);

    expect($requests)->toHaveCount(1)
        ->and($requests[0]->target)->toBe('/ready')
        ->and($requestRaw->ended)->toBeTrue()
        ->and($connection->activeRequestStreams())->toBe(0);
});

it('cancels request state when the peer resets a QUIC request stream', function (): void {
    $requestRaw = fakeHttp3ConnectionStream(0, true, [null]);
    $requestRaw->peerResetCode = 0x10c;
    $connectionRaw = fakeHttp3ConnectionRaw(
        [
            fakeHttp3ConnectionStream(3, false),
            fakeHttp3ConnectionStream(7, false),
            fakeHttp3ConnectionStream(11, false),
        ],
        [$requestRaw],
    );
    $connection = new PhpQuicHttp3Connection(
        new PhpQuicConnection($connectionRaw),
        static function (): void {},
    );

    $connection->pump();

    expect($connection->activeRequestStreams())->toBe(0)
        ->and($connection->closed())->toBeFalse();
});

it('treats connection poll errors as transport closure without sending a second close', function (): void {
    $events = new PhpQuicEventMasks(1, 2, 4, 8, 16);
    $connectionRaw = fakeHttp3ConnectionRaw(
        [
            fakeHttp3ConnectionStream(3, false),
            fakeHttp3ConnectionStream(7, false),
            fakeHttp3ConnectionStream(11, false),
        ],
        [],
    );
    $connection = new PhpQuicHttp3Connection(new PhpQuicConnection($connectionRaw), static function (): void {});

    $connection->handleReady([spl_object_id($connectionRaw) => $events->error], $events);

    expect($connection->closed())->toBeTrue()
        ->and($connectionRaw->closed)->toBe([]);
});

it('rejects invalid peer stream origin before it enters HTTP/3 protocol state', function (): void {
    $invalidPeer = fakeHttp3ConnectionStream(1, true);
    $connectionRaw = fakeHttp3ConnectionRaw(
        [
            fakeHttp3ConnectionStream(3, false),
            fakeHttp3ConnectionStream(7, false),
            fakeHttp3ConnectionStream(11, false),
        ],
        [$invalidPeer],
    );
    $connection = new PhpQuicHttp3Connection(
        new PhpQuicConnection($connectionRaw),
        static function (): void {},
    );

    expect(fn() => $connection->pump())->toThrow(Http3Exception::class)
        ->and($connection->closed())->toBeTrue()
        ->and($connectionRaw->closed)->toHaveCount(1);
});

it('sends one bounded GOAWAY and rejects request streams at the drain boundary', function (): void {
    $active = fakeHttp3ConnectionStream(0, true, ['']);
    $control = fakeHttp3ConnectionStream(3, false);
    $connectionRaw = fakeHttp3ConnectionRaw(
        [
            $control,
            fakeHttp3ConnectionStream(7, false),
            fakeHttp3ConnectionStream(11, false),
        ],
        [$active],
    );
    $connection = new PhpQuicHttp3Connection(
        new PhpQuicConnection($connectionRaw),
        static function (): void {},
    );
    $connection->pump();
    expect($connection->activeRequestStreams())->toBe(1);

    $connection->beginDrain();
    $controlAfterFirstDrain = $control->written;
    $connection->beginDrain();

    $frames = (new FrameParser())->push(substr($control->written, 1));
    $goaway = array_values(array_filter(
        $frames,
        static fn(Frame $frame): bool => $frame->knownType() === FrameType::GOAWAY,
    ));
    $offset = 0;
    $boundary = \Infocyph\Runwire\Http\Http3\VarIntCodec::decode($goaway[0]->payload, $offset);

    $rejected = fakeHttp3ConnectionStream(4, true, ['']);
    $connectionRaw->acceptedStreams[] = $rejected;
    $connection->pump();

    expect($connection->draining())->toBeTrue()
        ->and($control->written)->toBe($controlAfterFirstDrain)
        ->and($goaway)->toHaveCount(1)
        ->and($boundary)->toBe(4)
        ->and($offset)->toBe(strlen($goaway[0]->payload))
        ->and($rejected->peerResetCode)->toBe(ErrorCode::REQUEST_REJECTED->value)
        ->and($connection->activeRequestStreams())->toBe(1)
        ->and($connection->closed())->toBeFalse();
});
