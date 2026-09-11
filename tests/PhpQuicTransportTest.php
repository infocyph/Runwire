<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\Quic\PhpQuicStream;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicTransport;

function fakePhpQuicStream(int $id, bool $bidirectional, int $maxWrite = PHP_INT_MAX): object
{
    return new class($id, $bidirectional, $maxWrite) {
        public bool $ended = false;

        public ?int $resetCode = null;

        public string $written = '';

        public function __construct(
            private readonly int $id,
            private readonly bool $bidirectional,
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
        ->and($stream->read(1))->toBe('');

    $stream->reset(0x10c);
    $stream->end();

    expect($raw->resetCode)->toBe(0x10c)
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
    expect($requestRaw->ended)->toBeTrue();

    $transport->releaseRequestStream(0);
    expect(fn() => $transport->writeRequestStream(0, 'x'))->toThrow(LogicException::class);
});

it('rejects invalid stream direction and origin at the QUIC transport boundary', function (): void {
    expect(fn() => new PhpQuicTransport(new PhpQuicStream(fakePhpQuicStream(3, true))))
        ->toThrow(InvalidArgumentException::class);

    $transport = new PhpQuicTransport(new PhpQuicStream(fakePhpQuicStream(3, false)));
    expect(fn() => $transport->registerRequestStream(new PhpQuicStream(fakePhpQuicStream(2, false))))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => $transport->registerRequestStream(new PhpQuicStream(fakePhpQuicStream(1, true))))
        ->toThrow(InvalidArgumentException::class);
});
