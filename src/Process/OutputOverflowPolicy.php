<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process;

enum OutputOverflowPolicy: string
{
    case TERMINATE = 'terminate';
    case TRUNCATE = 'truncate';
}
