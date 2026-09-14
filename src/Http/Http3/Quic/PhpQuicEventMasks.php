<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Quic;

use InvalidArgumentException;

/**
 * Groups validated, non-overlapping native QUIC readiness event masks.
 */
final readonly class PhpQuicEventMasks
{
    /**
     * Create and validate a set of QUIC event masks.
     */
    public function __construct(
        public int $read,
        public int $write,
        public int $acceptConnection,
        public int $acceptStream,
        public int $error,
    ) {
        $masks = [
            'read' => $read,
            'write' => $write,
            'acceptConnection' => $acceptConnection,
            'acceptStream' => $acceptStream,
            'error' => $error,
        ];
        $seen = 0;
        foreach ($masks as $name => $mask) {
            if ($mask <= 0) {
                throw new InvalidArgumentException(sprintf('%s must be a positive QUIC event mask.', $name));
            }
            if (($seen & $mask) !== 0) {
                throw new InvalidArgumentException('QUIC event masks must not overlap.');
            }
            $seen |= $mask;
        }
    }

    /**
     * Load event masks from the available native QUIC extension.
     */
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
