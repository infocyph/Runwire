<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Server;

final readonly class BoundServer
{
    public function __construct(
        public Server $definition,
        public TcpListener $listener,
    ) {
    }
}
