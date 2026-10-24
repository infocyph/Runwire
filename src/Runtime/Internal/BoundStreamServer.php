<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Network\UnixListener;
use Infocyph\Runwire\StreamServer;

/**
 * Pairs a framed stream server definition with its bound transport listener.
 */
final readonly class BoundStreamServer
{
    /**
     * Creates a bound stream server value object.
     */
    public function __construct(
        public StreamServer $definition,
        public TcpListener|UnixListener $listener,
    ) {}
}
