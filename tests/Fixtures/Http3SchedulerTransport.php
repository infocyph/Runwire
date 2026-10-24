<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Fixtures;

use Closure;
use Infocyph\Runwire\Http\Http3\Internal\Http3TransportInterface;

final class Http3SchedulerTransport implements Http3TransportInterface
{
    /** @var array<int, string> */
    public array $requestBytes = [];

    /** @var list<int> */
    public array $finished = [];

    public bool $blocked = false;

    public int $maxWriteBytes = PHP_INT_MAX;

    public string $qpackBytes = '';

    public function finishRequestStream(int $streamId): void
    {
        $this->finished[] = $streamId;
    }

    public function writeQpackEncoder(string $bytes): int
    {
        return $this->write($bytes, function (string $accepted): void {
            $this->qpackBytes .= $accepted;
        });
    }

    public function writeRequestStream(int $streamId, string $bytes): int
    {
        return $this->write($bytes, function (string $accepted) use ($streamId): void {
            $this->requestBytes[$streamId] = ($this->requestBytes[$streamId] ?? '') . $accepted;
        });
    }

    private function write(string $bytes, Closure $accept): int
    {
        if ($this->blocked) {
            return 0;
        }

        $length = min(strlen($bytes), $this->maxWriteBytes);
        if ($length > 0) {
            $accept(substr($bytes, 0, $length));
        }

        return $length;
    }
}
