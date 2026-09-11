<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

enum RuntimeDriver: string
{
    case AUTO = 'auto';
    case NATIVE = 'native';
    case FPM = 'fpm';
    case FRANKENPHP = 'frankenphp';
    case SWOOLE = 'swoole';
    case ROADRUNNER = 'roadrunner';
}
