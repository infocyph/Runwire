<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

enum FrankenPhpMode: string
{
    case AUTO = 'auto';
    case CLASSIC = 'classic';
    case WORKER = 'worker';
}
