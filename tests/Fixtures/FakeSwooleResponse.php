<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Fixtures;

final class FakeSwooleResponse
{
    /** @var list<string> */
    public array $chunks = [];

    public int $ends = 0;

    /** @var list<array{0: string, 1: string}> */
    public array $headers = [];

    /** @var list<int> */
    public array $statuses = [];

    public function end(): bool
    {
        ++$this->ends;

        return true;
    }

    public function header(string $name, string $value): bool
    {
        $this->headers[] = [$name, $value];

        return true;
    }

    public function status(int $status): bool
    {
        $this->statuses[] = $status;

        return true;
    }

    public function write(string $chunk): bool
    {
        $this->chunks[] = $chunk;

        return true;
    }
}
