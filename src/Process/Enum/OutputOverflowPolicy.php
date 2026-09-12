<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process\Enum;

enum OutputOverflowPolicy: string
{
    case TERMINATE = 'terminate';

    case TRUNCATE = 'truncate';
}
