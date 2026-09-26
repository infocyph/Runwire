<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\ResponseTransfer;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

function responseTransferWriter(LoopInterface $loop, int $pressureEvery = 0): ResponseWriterInterface
{
    return new class($loop, $pressureEvery) implements ResponseWriterInterface {
        public string $body = '';

        public bool $ended = false;

        public bool $started = false;

        private $drainCallback = null;

        /** @var list<callable(self): void> */
        private array $terminal = [];

        private int $writes = 0;

        public function __construct(
            private readonly LoopInterface $loop,
            private readonly int $pressureEvery,
        ) {}

        public function end(string $finalChunk = ''): WriteResult
        {
            if ($this->ended) {
                return new WriteResult(WriteState::CLOSED, 0);
            }

            $this->started = true;
            $this->body .= $finalChunk;
            $this->ended = true;
            foreach ($this->terminal as $callback) {
                $callback($this);
            }
            $this->terminal = [];

            return new WriteResult(WriteState::ACCEPTED, 0);
        }

        public function isEnded(): bool
        {
            return $this->ended;
        }

        public function isStarted(): bool
        {
            return $this->started;
        }

        public function onDrain(callable $callback): self
        {
            $this->drainCallback = $callback;

            return $this;
        }

        public function onTerminal(callable $callback): self
        {
            if ($this->ended) {
                $callback($this);

                return $this;
            }

            $this->terminal[] = $callback;

            return $this;
        }

        public function start(int $status = 200, ?Headers $headers = null): WriteResult
        {
            unset($status, $headers);
            $this->started = true;

            return new WriteResult(WriteState::ACCEPTED, 0);
        }

        public function write(string $chunk): WriteResult
        {
            if ($this->ended) {
                return new WriteResult(WriteState::CLOSED, 0);
            }

            $this->started = true;
            $this->body .= $chunk;
            ++$this->writes;

            if ($this->pressureEvery > 0 && $this->writes % $this->pressureEvery === 0) {
                $this->loop->defer(function (): void {
                    $callback = $this->drainCallback;
                    $this->drainCallback = null;
                    if ($callback !== null) {
                        $callback($this);
                    }
                });

                return new WriteResult(WriteState::PRESSURED, strlen($chunk));
            }

            return new WriteResult(WriteState::ACCEPTED, 0);
        }
    };
}

function responseTransferSource(string $payload): mixed
{
    $source = fopen('php://temp', 'w+b');
    expect($source)->toBeResource();
    fwrite($source, $payload);
    rewind($source);

    return $source;
}

it('transfers bounded chunks with backpressure and cooperative fairness', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $writer = responseTransferWriter($loop, 3);
    $payload = str_repeat('runwire-transfer-', 8_192);
    $source = responseTransferSource($payload);
    $ticks = 0;

    $transferred = $runtime->run(static function (CoroutineScope $scope) use ($source, $writer, &$ticks): int {
        $scope->spawn(static function () use ($scope, &$ticks): void {
            for ($index = 0; $index < 8; ++$index) {
                ++$ticks;
                $scope->yieldNow();
            }
        });

        return ResponseTransfer::stream(
            $scope,
            $source,
            $writer,
            chunkBytes: 4_096,
            chunksPerTurn: 2,
        );
    });

    expect($transferred)->toBe(strlen($payload))
        ->and($writer->body)->toBe($payload)
        ->and($writer->isEnded())->toBeTrue()
        ->and($ticks)->toBeGreaterThan(0)
        ->and(is_resource($source))->toBeFalse();
});

it('cancels a blocked transfer and closes its source', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $context = RequestContext::standalone();
    [$source, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($source)->toBeResource()->and($peer)->toBeResource();
    $writer = responseTransferWriter($loop);

    $loop->delay(0.01, static function () use ($context): void {
        $context->cancel(CancellationReason::HOST_CANCELLED);
    });

    expect(static function () use ($runtime, $context, $source, $writer): void {
        $runtime->runRequest(
            $context,
            static fn(CoroutineScope $scope): int => ResponseTransfer::stream($scope, $source, $writer),
        );
    })->toThrow(CancelledException::class);

    expect(is_resource($source))->toBeFalse()
        ->and($writer->isEnded())->toBeFalse();

    fclose($peer);
});

it('can preserve caller source ownership and blocking mode', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $writer = responseTransferWriter($loop);
    $source = responseTransferSource('preserved-source');
    $before = stream_get_meta_data($source);

    $transferred = $runtime->run(
        static fn(CoroutineScope $scope): int => ResponseTransfer::stream(
            $scope,
            $source,
            $writer,
            chunkBytes: 4,
            chunksPerTurn: 1,
            closeSource: false,
        ),
    );
    $after = stream_get_meta_data($source);

    expect($transferred)->toBe(16)
        ->and($writer->body)->toBe('preserved-source')
        ->and(is_resource($source))->toBeTrue()
        ->and((bool) ($after['blocked'] ?? false))->toBe((bool) ($before['blocked'] ?? false));

    fclose($source);
});

it('rejects invalid transfer sources and unsafe chunk policies', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $writer = responseTransferWriter($loop);

    expect(static function () use ($runtime, $writer): void {
        $runtime->run(
            static fn(CoroutineScope $scope): int => ResponseTransfer::stream($scope, 'not-a-stream', $writer),
        );
    })->toThrow(InvalidArgumentException::class);

    $source = responseTransferSource('x');
    try {
        expect(static function () use ($runtime, $source, $writer): void {
            $runtime->run(
                static fn(CoroutineScope $scope): int => ResponseTransfer::stream(
                    $scope,
                    $source,
                    $writer,
                    chunkBytes: 0,
                ),
            );
        })->toThrow(InvalidArgumentException::class);
    } finally {
        if (is_resource($source)) {
            fclose($source);
        }
    }
});
