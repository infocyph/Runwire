<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Enum;

/**
 * Selects FrankenPHP automatic, classic, or worker execution mode.
 */
enum FrankenPhpMode: string
{
    case AUTO = 'auto';

    case CLASSIC = 'classic';

    case WORKER = 'worker';
}
