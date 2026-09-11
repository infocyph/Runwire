<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

final readonly class DecoderInstruction
{
    public function __construct(public DecoderInstructionType $type, public int $value) {}
}
