<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process\Enum;

/**
 * Identifies why a child process stopped running.
 */
enum TerminationReason: string
{
    case EXITED = 'exited';

    case OUTPUT_LIMIT = 'output_limit';

    case TIMEOUT = 'timeout';
}
