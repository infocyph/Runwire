<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Enum;

enum OpcacheMode: string
{
    case AUTO = 'auto';

    case OFF = 'off';

    case ON = 'on';

    case REQUIRED = 'required';
}
