<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Fixtures;

final class FakeSwooleRequest
{
    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $header
     */
    public function __construct(
        public array $server,
        public array $header,
        private readonly string $body,
    ) {}

    public function rawContent(): string
    {
        return $this->body;
    }
}
