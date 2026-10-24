<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Enum;

/**
 * Identifies application boot and warmup startup phases.
 */
enum ApplicationStartupPhase: string
{
    case BOOT = 'boot';

    case WARMUP = 'warmup';
}
