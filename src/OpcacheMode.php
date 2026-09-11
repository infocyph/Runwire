<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

enum OpcacheMode: string
{
    case AUTO = 'auto';
    case ON = 'on';
    case OFF = 'off';
    case REQUIRED = 'required';
}
