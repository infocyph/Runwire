<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Enum\FrameType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\FrameParser;
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

        public int $readBytes = 0;

        public ?Closure $writeFailure = null;

        public bool $writeFails = false;

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
                $this->readBytes += strlen($next);

                return $next;
            }

            $chunk = substr($next, 0, $length);
            $this->readBytes += strlen($chunk);
            array_unshift($this->reads, substr($next, $length));

            return $chunk;
        }

        public function reset(int $errorCode = 0): void
        {
            $this->peerResetCode = $errorCode;
        }

        public function write(string $data, bool $fin = false): int
        {
            if ($this->writeFails) {
                ($this->writeFailure)?->__invoke();

                throw new RuntimeException('Native QUIC stream is already shut down.');
            }

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

        /** @var array{error_code: int, frame_type: int, reason: string, local: bool, transport: bool}|null */
        public ?array $closeInfo = null;

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

        public function getCloseInfo(): ?array
        {
            return $this->closeInfo;
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
        (new Frame(FrameType::HEADERS->value, $headerBlock))->encode(),
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
        (new Frame(FrameType::HEADERS->value, $encoder->encode([
            [':method', 'GET'],
            [':scheme', 'https'],
            [':authority', 'example.com'],
            [':path', '/ready'],
        ], 0)->block))->encode(),
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

it('treats native peer closure before or during GOAWAY flush as an idempotent drain', function (): void {
    $control = fakeHttp3ConnectionStream(3, false);
    $connectionRaw = fakeHttp3ConnectionRaw(
        [
            $control,
            fakeHttp3ConnectionStream(7, false),
            fakeHttp3ConnectionStream(11, false),
        ],
        [],
    );
    $connection = new PhpQuicHttp3Connection(new PhpQuicConnection($connectionRaw), static function (): void {});

    $connectionRaw->closeInfo = [
        'error_code' => 0,
        'frame_type' => 0,
        'reason' => '',
        'local' => false,
        'transport' => false,
    ];
    $control->writeFails = true;

    $connection->beginDrain();

    expect($connection->closed())->toBeTrue()
        ->and($connection->draining())->toBeFalse();

    $control = fakeHttp3ConnectionStream(3, false);
    $connectionRaw = fakeHttp3ConnectionRaw(
        [
            $control,
            fakeHttp3ConnectionStream(7, false),
            fakeHttp3ConnectionStream(11, false),
        ],
        [],
    );
    $connection = new PhpQuicHttp3Connection(new PhpQuicConnection($connectionRaw), static function (): void {});
    $control->writeFails = true;
    $control->writeFailure = static function () use ($connectionRaw): void {
        $connectionRaw->closeInfo = [
            'error_code' => 0,
            'frame_type' => 0,
            'reason' => '',
            'local' => false,
            'transport' => false,
        ];
    };

    $connection->beginDrain();

    expect($connection->closed())->toBeTrue();
});

it('keeps unrelated HTTP3 drain write failures visible', function (): void {
    $control = fakeHttp3ConnectionStream(3, false);
    $connectionRaw = fakeHttp3ConnectionRaw(
        [
            $control,
            fakeHttp3ConnectionStream(7, false),
            fakeHttp3ConnectionStream(11, false),
        ],
        [],
    );
    $connection = new PhpQuicHttp3Connection(new PhpQuicConnection($connectionRaw), static function (): void {});
    $control->writeFails = true;

    expect(fn() => $connection->beginDrain())->toThrow(RuntimeException::class)
        ->and($connection->closed())->toBeFalse();
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
    $encoder = new Encoder(0, 0);
    $active = fakeHttp3ConnectionStream(0, true, [
        (new Frame(FrameType::HEADERS->value, $encoder->encode([
            [':method', 'GET'],
            [':scheme', 'https'],
            [':authority', 'example.com'],
            [':path', '/draining'],
        ], 0)->block))->encode(),
        '',
    ]);
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


it('enforces the HTTP3 aggregate control-stream byte budget per pump', function (): void {
    $peerControl = fakeHttp3ConnectionStream(2, false, [str_repeat("\x21", 128)]);
    $connectionRaw = fakeHttp3ConnectionRaw(
        [
            fakeHttp3ConnectionStream(3, false),
            fakeHttp3ConnectionStream(7, false),
            fakeHttp3ConnectionStream(11, false),
        ],
        [$peerControl],
    );
    $connection = new PhpQuicHttp3Connection(
        new PhpQuicConnection($connectionRaw),
        static function (): void {},
        new Http3Limits(
            maxControlBytesPerTick: 16,
            streamReadChunkBytes: 64,
        ),
    );

    $connection->pump();

    expect($peerControl->readBytes)->toBe(16)
        ->and($connection->closed())->toBeFalse();
});


it('rotates scan-based HTTP3 reads so reserved streams cannot starve requests', function (): void {
    $encoder = new Encoder(0, 0);
    $reservedRaw = fakeHttp3ConnectionStream(2, false, ["\x21", '', '', '', '']);
    $requestRaw = fakeHttp3ConnectionStream(0, true, [
        (new Frame(FrameType::HEADERS->value, $encoder->encode([
            [':method', 'GET'],
            [':scheme', 'https'],
            [':authority', 'example.com'],
            [':path', '/fair-scan'],
        ], 0)->block))->encode(),
        null,
    ]);
    $connectionRaw = fakeHttp3ConnectionRaw(
        [
            fakeHttp3ConnectionStream(3, false),
            fakeHttp3ConnectionStream(7, false),
            fakeHttp3ConnectionStream(11, false),
        ],
        [$reservedRaw, $requestRaw],
    );
    $requests = [];
    $connection = new PhpQuicHttp3Connection(
        new PhpQuicConnection($connectionRaw),
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$requests): void {
            $requests[] = $request;
            $writer->end('ok');
        },
        new Http3Limits(maxReadsPerPump: 1),
    );

    $connection->pump();
    expect($requestRaw->readBytes)->toBe(0);

    $connection->pump();
    expect($requestRaw->readBytes)->toBeGreaterThan(0);

    $connection->pump();
    $connection->pump();

    expect($requests)->toHaveCount(1)
        ->and($requests[0]->target)->toBe('/fair-scan')
        ->and($connection->closed())->toBeFalse();
});

it('rotates readiness-based HTTP3 reads so reserved streams cannot starve requests', function (): void {
    $events = new PhpQuicEventMasks(1, 2, 4, 8, 16);
    $encoder = new Encoder(0, 0);
    $reservedRaw = fakeHttp3ConnectionStream(2, false, ["\x21", '', '', '']);
    $requestRaw = fakeHttp3ConnectionStream(0, true, [
        (new Frame(FrameType::HEADERS->value, $encoder->encode([
            [':method', 'GET'],
            [':scheme', 'https'],
            [':authority', 'example.com'],
            [':path', '/fair-ready'],
        ], 0)->block))->encode(),
        null,
    ]);
    $connectionRaw = fakeHttp3ConnectionRaw(
        [
            fakeHttp3ConnectionStream(3, false),
            fakeHttp3ConnectionStream(7, false),
            fakeHttp3ConnectionStream(11, false),
        ],
        [$reservedRaw, $requestRaw],
    );
    $connection = new PhpQuicHttp3Connection(
        new PhpQuicConnection($connectionRaw),
        static function (): void {},
        new Http3Limits(maxReadsPerPump: 1),
    );

    $connection->handleReady(
        [spl_object_id($connectionRaw) => $events->acceptStream],
        $events,
    );
    $ready = [
        spl_object_id($reservedRaw) => $events->read,
        spl_object_id($requestRaw) => $events->read,
    ];

    $connection->handleReady($ready, $events);
    expect($requestRaw->readBytes)->toBe(0);

    $connection->handleReady($ready, $events);

    expect($requestRaw->readBytes)->toBeGreaterThan(0)
        ->and($connection->closed())->toBeFalse();
});


it('keeps accepted HTTP3 headers admissible through application draining', function (): void {
    $block = (new Encoder(0, 0))->encode([
        [':method', 'GET'], [':scheme', 'https'], [':authority', 'example.com'], [':path', '/accepted'],
    ], 0)->block;
    $frame = new Frame(FrameType::HEADERS->value, $block)->encode();
    $stream = fakeHttp3ConnectionStream(0, true, [substr($frame, 0, 1), '', substr($frame, 1), null]);
    $raw = fakeHttp3ConnectionRaw([
        fakeHttp3ConnectionStream(3, false), fakeHttp3ConnectionStream(7, false), fakeHttp3ConnectionStream(11, false),
    ], [$stream]);
    $events = [];
    $runtime = \Infocyph\Runwire\RuntimeContext::standalone();
    $application = new \Infocyph\Runwire\Runtime\Host\RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$events): void {
            $events[] = $request->target;
            $writer->end('accepted-h3');
        },
        runtimeContext: $runtime,
        lifecycle: new \Infocyph\Runwire\Runtime\ApplicationLifecycleHooks(drain: static function () use (&$events): void { $events[] = 'drain'; }),
    );
    $connection = new PhpQuicHttp3Connection(new PhpQuicConnection($raw), $application->handle(...));
    $connection->pump();
    $listener = new \Infocyph\Runwire\Http\Http3\Quic\PhpQuicListener(new class {
        public function accept(): ?object { return null; }
        public function close(): void {}
        public function setBlocking(bool $blocking): void { unset($blocking); }
    });
    $masks = new PhpQuicEventMasks(1, 2, 4, 8, 16);
    $poller = new \Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Poller($masks, static function (array $items) use ($masks): array {
        $ready = [];
        foreach ($items as $id => $item) {
            $ready[$id] = $item[1] & ~$masks->error;
        }
        return $ready;
    });
    $worker = new \Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Worker($listener, $application->handle(...), new Http3Limits(), 4, $poller);
    (new ReflectionProperty($worker, 'connections'))->setValue($worker, [spl_object_id($raw) => $connection]);
    $loop = new \Infocyph\Runwire\Loop\SelectLoop();
    $taskLoop = new \Infocyph\Runwire\Loop\SelectLoop();
    $context = new \Infocyph\Runwire\Supervisor\WorkerContext('h3', 0, 1, getmypid(), 0);
    $context->attachLoop($taskLoop);
    $sampler = new \Infocyph\Runwire\Runtime\Internal\WorkerDiagnosticsSampler($context, $runtime->metrics, new \Infocyph\Runwire\Metrics\DiagnosticsPolicy(), $taskLoop);
    $attachment = new \Infocyph\Runwire\Runtime\Internal\NativeHttp3Attachment($loop, $taskLoop, $context, $application, $worker, $sampler, static function (): void {});
    $handle = $attachment->start(0.01);

    try {
        expect($connection->activeRequestStreams())->toBe(1);
        $handle->stop();
        $loop->tick();
        $rejected = fakeHttp3ConnectionStream(4, true, ['']);
        $raw->acceptedStreams[] = $rejected;
        $loop->delay(0.05, static fn() => $loop->stop());
        $loop->run();

        expect($stream->written)->toContain('accepted-h3')
            ->and($stream->ended)->toBeTrue()
            ->and($events)->toBe(['/accepted', 'drain'])
            ->and($rejected->peerResetCode)->toBe(ErrorCode::REQUEST_REJECTED->value);
    } finally {
        $handle->close();
        $context->close();
    }
});
