<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Network\UnixListener;
use Infocyph\Runwire\StreamServer;

final readonly class BoundStreamServer
{
    public function __construct(
        public StreamServer $definition,
        public TcpListener|UnixListener $listener,
    ) {
    }
}
