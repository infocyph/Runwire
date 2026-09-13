<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Enum;

enum ApplicationStartupPhase: string
{
    case BOOT = 'boot';

    case WARMUP = 'warmup';
}
