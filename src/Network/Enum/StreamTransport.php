<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Enum;

enum StreamTransport: string
{
    case TCP = 'tcp';

    case UNIX = 'unix';
}
