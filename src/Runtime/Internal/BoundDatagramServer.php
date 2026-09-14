<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\DatagramServer;
use Infocyph\Runwire\Network\DatagramListener;

/**
 * Pairs a datagram server definition with its bound listener.
 */
final readonly class BoundDatagramServer
{
    /**
     * Creates a bound datagram server value object.
     */
    public function __construct(
        public DatagramServer $definition,
        public DatagramListener $listener,
    ) {}
}
