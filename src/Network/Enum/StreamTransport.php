<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Enum;

/**
 * Identifies supported stream transport families.
 */
enum StreamTransport: string
{
    case TCP = 'tcp';

    case UNIX = 'unix';
}
