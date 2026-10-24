<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process\Enum;

/**
 * Determines how process execution reacts when captured output exceeds its limit.
 */
enum OutputOverflowPolicy: string
{
    case TERMINATE = 'terminate';

    case TRUNCATE = 'truncate';
}
