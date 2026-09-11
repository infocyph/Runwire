<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\Quic\PhpQuicApi;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicConnection;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicListener;

function fakePhpQuicFacadeStream(int $id, bool $bidirectional): object
{
    return new class($id, $bidirectional) {
        public bool $ended = false;

        public function __construct(
            private readonly int $id,
            private readonly bool $bidirectional,
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

        public function reset(int $errorCode = 0): void {}

        public function write(string $data, bool $fin = false): int
        {
            if ($fin) {
                $this->ended = true;
            }

            return strlen($data);
        }
    };
}

it('reports php-quic availability from runtime symbols instead of a hard dependency', function (): void {
    $expected = extension_loaded('quic')
        && class_exists('Quic\\Listener')
        && class_exists('Quic\\Connection')
        && class_exists('Quic\\Stream')
        && is_callable('Quic\\poll')
        && defined('Quic\\POLL_READ')
        && defined('Quic\\POLL_WRITE')
        && defined('Quic\\POLL_ACCEPT_CONNECTION')
        && defined('Quic\\POLL_ACCEPT_STREAM')
        && defined('Quic\\POLL_ERROR');

    expect(PhpQuicApi::available())->toBe($expected);
});

it('wraps accepted and locally opened php-quic streams and enforces non-blocking connections', function (): void {
    $peerStream = fakePhpQuicFacadeStream(0, true);
    $localStream = fakePhpQuicFacadeStream(3, false);
    $raw = new class($peerStream, $localStream) {
        public bool $blocking = true;

        /** @var list<array{0: int, 1: string, 2: bool}> */
        public array $closed = [];

        private ?object $accepted;

        public function __construct(object $peerStream, private readonly object $localStream)
        {
            $this->accepted = $peerStream;
        }

        public function acceptStream(): ?object
        {
            $stream = $this->accepted;
            $this->accepted = null;

            return $stream;
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
            return $this->localStream;
        }

        public function setBlocking(bool $blocking): void
        {
            $this->blocking = $blocking;
        }
    };
    $connection = new PhpQuicConnection($raw);

    $connection->setNonBlocking();
    expect($raw->blocking)->toBeFalse()
        ->and($connection->negotiatedAlpn())->toBe('h3')
        ->and($connection->acceptStream()?->id())->toBe(0)
        ->and($connection->acceptStream())->toBeNull()
        ->and($connection->openStream(false)->id())->toBe(3);

    $connection->close(0x100, 'done');
    expect($raw->closed)->toBe([[0x100, 'done', true]]);
});

it('wraps listener acceptance and immediately makes accepted connections non-blocking', function (): void {
    $connection = new class {
        public bool $blocking = true;

        public function acceptStream(): ?object
        {
            return null;
        }

        public function close(int $errorCode = 0, string $reason = '', bool $rapid = false): void {}

        public function getNegotiatedAlpn(): ?string
        {
            return 'h3';
        }

        public function openStream(bool $bidirectional = true): object
        {
            return fakePhpQuicFacadeStream(3, false);
        }

        public function setBlocking(bool $blocking): void
        {
            $this->blocking = $blocking;
        }
    };
    $raw = new class($connection) {
        public bool $blocking = true;

        public bool $closed = false;

        private ?object $connection;

        public function __construct(object $connection)
        {
            $this->connection = $connection;
        }

        public function accept(): ?object
        {
            $connection = $this->connection;
            $this->connection = null;

            return $connection;
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
    $listener = new PhpQuicListener($raw);
    $listener->setNonBlocking();
    $accepted = $listener->accept();

    expect($raw->blocking)->toBeFalse()
        ->and($accepted)->toBeInstanceOf(PhpQuicConnection::class)
        ->and($connection->blocking)->toBeFalse()
        ->and($listener->accept())->toBeNull();

    $listener->close();
    expect($raw->closed)->toBeTrue();
});
