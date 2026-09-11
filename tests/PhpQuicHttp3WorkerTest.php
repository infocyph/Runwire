<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicEventMasks;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Poller;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Worker;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicListener;

function workerQuicStream(int $id, bool $bidirectional): object
{
    return new class($id, $bidirectional) {
        public function __construct(
            private readonly int $id,
            private readonly bool $bidirectional,
        ) {}

        public function end(): void {}

        public function getId(): int
        {
            return $this->id;
        }

        public function getResetCode(): ?int
        {
            return null;
        }

        public function isBidirectional(): bool
        {
            return $this->bidirectional;
        }

        public function read(int $length): ?string
        {
            return $length > 0 ? '' : null;
        }

        public function reset(int $errorCode = 0): void {}

        public function write(string $data): int
        {
            return strlen($data);
        }
    };
}

function workerQuicConnection(): object
{
    return new class {
        public bool $blocking = true;

        /** @var list<array{0: int, 1: string, 2: bool}> */
        public array $closed = [];

        /** @var list<object> */
        private array $localStreams;

        public function __construct()
        {
            $this->localStreams = [
                workerQuicStream(3, false),
                workerQuicStream(7, false),
                workerQuicStream(11, false),
            ];
        }

        public function acceptStream(): ?object
        {
            return null;
        }

        public function close(int $errorCode = 0, string $reason = '', bool $rapid = false): void
        {
            $this->closed[] = [$errorCode, $reason, $rapid];
        }

        public function getNegotiatedAlpn(): ?string
        {
            return 'h3';
        }

        public function openStream(bool $bidirectional = true): object
        {
            $stream = array_shift($this->localStreams);
            if (!is_object($stream) || $stream->isBidirectional() !== $bidirectional) {
                throw new RuntimeException('Invalid worker test QUIC stream.');
            }

            return $stream;
        }

        public function setBlocking(bool $blocking): void
        {
            $this->blocking = $blocking;
        }
    };
}

function workerQuicListener(array $connections): object
{
    return new class($connections) {
        public bool $blocking = true;

        public bool $closed = false;

        /** @param list<object> $connections */
        public function __construct(private array $connections) {}

        public function accept(): ?object
        {
            return array_shift($this->connections);
        }

        public function close(): void
        {
            $this->closed = true;
        }

        public function setBlocking(bool $blocking): void
        {
            $this->blocking = $blocking;
        }
    };
}

it('polls one worker-wide QUIC set and removes listener accept pressure at capacity', function (): void {
    $events = new PhpQuicEventMasks(1, 2, 4, 8, 16);
    $connectionRaw = workerQuicConnection();
    $listenerRaw = workerQuicListener([$connectionRaw]);
    $calls = [];
    $poller = new PhpQuicHttp3Poller(
        $events,
        static function (array $items, ?float $timeout) use (&$calls, $listenerRaw, $connectionRaw, $events): array {
            $calls[] = [$items, $timeout];
            if (count($calls) === 1) {
                return [spl_object_id($listenerRaw) => $events->acceptConnection];
            }
            if (count($calls) === 3) {
                return [spl_object_id($connectionRaw) => $events->error];
            }

            return [];
        },
    );
    $worker = new PhpQuicHttp3Worker(
        new PhpQuicListener($listenerRaw),
        static function (): void {},
        new Http3Limits(),
        1,
        $poller,
    );

    $worker->tick(0.0);
    expect($worker->connectionCount())->toBe(1)
        ->and($calls)->toHaveCount(1);

    $worker->tick(0.0);
    $secondItems = $calls[1][0];
    expect($calls)->toHaveCount(2)
        ->and(count($secondItems))->toBeGreaterThan(1)
        ->and($secondItems[spl_object_id($listenerRaw)][1] & $events->acceptConnection)->toBe(0)
        ->and($secondItems[spl_object_id($listenerRaw)][1] & $events->error)->not->toBe(0)
        ->and($secondItems[spl_object_id($connectionRaw)][1] & $events->acceptStream)->not->toBe(0);

    $worker->tick(0.0);
    expect($calls)->toHaveCount(3)
        ->and($worker->connectionCount())->toBe(0)
        ->and($worker->drainComplete())->toBeTrue();

    $worker->stopAccepting();
    expect($worker->accepting())->toBeFalse()
        ->and($listenerRaw->closed)->toBeTrue();
});
