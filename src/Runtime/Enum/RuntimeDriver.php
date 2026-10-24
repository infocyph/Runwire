<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Enum;

/**
 * Identifies supported native and host-owned runtime drivers.
 */
enum RuntimeDriver: string
{
    case AUTO = 'auto';

    case FPM = 'fpm';

    case FRANKENPHP = 'frankenphp';

    case NATIVE = 'native';

    case ROADRUNNER = 'roadrunner';

    case SWOOLE = 'swoole';
}
