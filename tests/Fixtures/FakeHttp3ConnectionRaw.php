<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Fixtures;

use RuntimeException;

/** In-memory QUIC transport used by HTTP/3 integration tests. */
final class FakeHttp3ConnectionRaw
{
    public bool $blocking = true;

    /** @var list<array{0: int, 1: string, 2: bool}> */
    public array $closed = [];

    /** @var array{error_code: int, frame_type: int, reason: string, local: bool, transport: bool}|null */
    public ?array $closeInfo = null;

    /** @param list<FakeHttp3ConnectionStream> $localStreams
     * @param list<FakeHttp3ConnectionStream> $acceptedStreams */
    public function __construct(
        public array $localStreams,
        public array $acceptedStreams,
        public readonly string $alpn,
    ) {}

    public function acceptStream(): ?FakeHttp3ConnectionStream
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

    public function getNegotiatedAlpn(): string
    {
        return $this->alpn;
    }

    /**
     * @throws \RuntimeException
     */
    public function openStream(bool $bidirectional = true): FakeHttp3ConnectionStream
    {
        $stream = array_shift($this->localStreams);
        if ($stream === null || $stream->isBidirectional() !== $bidirectional) {
            throw new RuntimeException('Invalid local test QUIC stream.');
        }

        return $stream;
    }

    public function setBlocking(bool $blocking): void
    {
        $this->blocking = $blocking;
    }
}
