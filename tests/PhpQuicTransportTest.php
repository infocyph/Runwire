<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\Quic\PhpQuicStream;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicTransport;

function fakePhpQuicStream(int $id, bool $bidirectional, int $maxWrite = PHP_INT_MAX): object
{
    return new class($id, $bidirectional, $maxWrite) {
        public bool $ended = false;

        public bool $failEnd = false;

        public bool $failWrite = false;

        public ?int $resetCode = null;

        public string $written = '';

        public function __construct(
            private readonly int $id,
            private readonly bool $bidirectional,
            private readonly int $maxWrite,
        ) {}

        public function end(): void
        {
            if ($this->failEnd) {
                throw new RuntimeException('Native QUIC stream reset while concluding.');
            }
            $this->ended = true;
        }

        public function getId(): int
        {
            return $this->id;
        }

        public function getResetCode(): ?int
        {
            return $this->resetCode;
        }

        public function isBidirectional(): bool
        {
            return $this->bidirectional;
        }

        public function read(int $length): ?string
        {
            return $length > 0 ? '' : null;
        }

        public function reset(int $errorCode = 0): void
        {
            $this->resetCode = $errorCode;
        }

        public function write(string $data, bool $fin = false): int
        {
            if ($this->failWrite) {
                throw new RuntimeException('Native QUIC stream reset while writing.');
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

it('validates and exposes the php-quic stream contract without requiring the extension', function (): void {
    $raw = fakePhpQuicStream(0, true, 3);
    $stream = new PhpQuicStream($raw);

    expect($stream->id())->toBe(0)
        ->and($stream->bidirectional())->toBeTrue()
        ->and($stream->write('abcdef'))->toBe(3)
        ->and($raw->written)->toBe('abc')
        ->and($stream->read(1))->toBe('')
        ->and($stream->resetCode())->toBeNull();

    $stream->reset(0x10c);
    $stream->end();

    expect($raw->resetCode)->toBe(0x10c)
        ->and($stream->resetCode())->toBe(0x10c)
        ->and($raw->ended)->toBeTrue();
});

it('maps HTTP/3 response transport operations onto exact php-quic stream writes', function (): void {
    $qpackRaw = fakePhpQuicStream(3, false, 2);
    $requestRaw = fakePhpQuicStream(0, true, 4);
    $transport = new PhpQuicTransport(new PhpQuicStream($qpackRaw));
    $transport->registerRequestStream(new PhpQuicStream($requestRaw));

    expect($transport->writeQpackEncoder('qpack'))->toBe(2)
        ->and($transport->writeRequestStream(0, 'response'))->toBe(4)
        ->and($qpackRaw->written)->toBe('qp')
        ->and($requestRaw->written)->toBe('resp');

    $transport->finishRequestStream(0);
    expect($requestRaw->ended)->toBeTrue()
        ->and($transport->responseFinished(0))->toBeTrue();

    $transport->releaseRequestStream(0);
    expect(fn() => $transport->writeRequestStream(0, 'x'))->toThrow(LogicException::class);
});

it('retains the QPACK encoder stream preamble across partial writes', function (): void {
    $qpackRaw = fakePhpQuicStream(3, false, 1);
    $transport = new PhpQuicTransport(new PhpQuicStream($qpackRaw), 'abc');

    expect($transport->writeQpackEncoder('payload'))->toBe(0)
        ->and($qpackRaw->written)->toBe('a')
        ->and($transport->qpackEncoderPreamblePending())->toBeTrue();

    $transport->flushQpackEncoderPreamble();
    $transport->flushQpackEncoderPreamble();

    expect($transport->qpackEncoderPreamblePending())->toBeFalse()
        ->and($qpackRaw->written)->toBe('abc')
        ->and($transport->writeQpackEncoder('z'))->toBe(1)
        ->and($qpackRaw->written)->toBe('abcz');
});

it('rejects invalid stream direction and origin at the QUIC transport boundary', function (): void {
    expect(fn() => new PhpQuicTransport(new PhpQuicStream(fakePhpQuicStream(3, true))))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new PhpQuicTransport(new PhpQuicStream(fakePhpQuicStream(2, false))))
        ->toThrow(InvalidArgumentException::class);

    $transport = new PhpQuicTransport(new PhpQuicStream(fakePhpQuicStream(3, false)));
    expect(fn() => $transport->registerRequestStream(new PhpQuicStream(fakePhpQuicStream(2, false))))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => $transport->registerRequestStream(new PhpQuicStream(fakePhpQuicStream(1, true))))
        ->toThrow(InvalidArgumentException::class);
});

it('isolates peer STOP_SENDING during native FIN and write without hiding other QUIC faults', function (): void {
    $resetRaw = fakePhpQuicStream(0, true);
    $healthyRaw = fakePhpQuicStream(4, true);
    $transport = new PhpQuicTransport(new PhpQuicStream(fakePhpQuicStream(3, false)));
    $transport->registerRequestStream(new PhpQuicStream($resetRaw));
    $transport->registerRequestStream(new PhpQuicStream($healthyRaw));

    $resetRaw->resetCode = 0x10c;
    $resetRaw->failEnd = true;
    $transport->finishRequestStream(0);

    expect($transport->responseFinished(0))->toBeTrue()
        ->and($resetRaw->ended)->toBeFalse()
        ->and($transport->writeRequestStream(4, 'healthy'))->toBe(7);

    $resetRaw->failWrite = true;
    expect($transport->writeRequestStream(0, 'discard'))->toBe(0);

    $transport->finishRequestStream(4);
    expect($transport->responseFinished(4))->toBeTrue()
        ->and($healthyRaw->ended)->toBeTrue()
        ->and($healthyRaw->written)->toBe('healthy');

    $resetRaw->resetCode = null;
    expect(fn() => $transport->writeRequestStream(0, 'fail'))
        ->toThrow(RuntimeException::class)
        ->and(fn() => $transport->finishRequestStream(0))
        ->toThrow(RuntimeException::class);
});
