<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use InvalidArgumentException;

final readonly class PhpQuicEventMasks
{
    public function __construct(
        public int $read,
        public int $write,
        public int $acceptConnection,
        public int $acceptStream,
        public int $error,
    ) {
        foreach (get_object_vars($this) as $name => $mask) {
            if ($mask <= 0 || ($mask & ($mask - 1)) !== 0) {
                throw new InvalidArgumentException(sprintf('%s must be a positive single-bit QUIC event mask.', $name));
            }
        }
        if (count(array_unique(get_object_vars($this))) !== 5) {
            throw new InvalidArgumentException('QUIC event masks must be distinct.');
        }
    }

    public static function native(): self
    {
        return new self(
            PhpQuicApi::readEvent(),
            PhpQuicApi::writeEvent(),
            PhpQuicApi::acceptConnectionEvent(),
            PhpQuicApi::acceptStreamEvent(),
            PhpQuicApi::errorEvent(),
        );
    }
}
