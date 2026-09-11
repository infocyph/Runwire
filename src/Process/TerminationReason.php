<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process;

enum TerminationReason: string
{
    case EXITED = 'exited';
    case TIMEOUT = 'timeout';
    case OUTPUT_LIMIT = 'output_limit';
}
