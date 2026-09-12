<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Enum;

enum RuntimeDriver: string
{
    case AUTO = 'auto';

    case FPM = 'fpm';

    case FRANKENPHP = 'frankenphp';

    case NATIVE = 'native';

    case ROADRUNNER = 'roadrunner';

    case SWOOLE = 'swoole';
}
