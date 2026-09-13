<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Enum;

enum TaskLocalInheritance: string
{
    case NONE = 'none';

    case SNAPSHOT = 'snapshot';
}
