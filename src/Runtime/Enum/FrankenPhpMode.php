<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Enum;

enum FrankenPhpMode: string
{
    case AUTO = 'auto';

    case CLASSIC = 'classic';

    case WORKER = 'worker';
}
