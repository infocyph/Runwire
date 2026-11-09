<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Fixtures;

use Closure;
use RuntimeException;

/** In-memory QUIC stream used by HTTP/3 integration tests. */
final class FakeHttp3ConnectionStream
{
    public bool $ended = false;

    public ?int $peerResetCode = null;

    public int $readBytes = 0;

    public ?Closure $writeFailure = null;

    public bool $writeFails = false;

    public string $written = '';

    /** @param list<string|null> $reads */
    public function __construct(
        public readonly int $id,
        public readonly bool $bidirectional,
        public array $reads,
        public readonly int $maxWrite,
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

    /**
     * @throws \RuntimeException
     */
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
}
