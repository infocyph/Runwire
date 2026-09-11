<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\DatagramServer;
use Infocyph\Runwire\Network\DatagramListener;

final readonly class BoundDatagramServer
{
    public function __construct(
        public DatagramServer $definition,
        public DatagramListener $listener,
    ) {}
}
