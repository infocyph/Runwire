<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Internal;

interface Http3TransportInterface
{
    public function finishRequestStream(int $streamId): void;

    public function writeQpackEncoder(string $bytes): int;

    public function writeRequestStream(int $streamId, string $bytes): int;
}
