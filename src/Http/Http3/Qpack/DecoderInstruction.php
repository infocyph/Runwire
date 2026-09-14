<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

use Infocyph\Runwire\Http\Http3\Qpack\Enum\DecoderInstructionType;

/**
 * Represents one decoded QPACK decoder-stream instruction.
 */
final readonly class DecoderInstruction
{
    /**
     * Create a decoder instruction from its type and integer value.
     */
    public function __construct(public DecoderInstructionType $type, public int $value) {}
}
