<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Server;

/**
 * Pairs an HTTP server definition with its bound TCP listener.
 */
final readonly class BoundServer
{
    /**
     * Creates a bound HTTP server value object.
     */
    public function __construct(
        public Server $definition,
        public TcpListener $listener,
    ) {}
}
