<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Process;

enum IoMode: string
{
    case CAPTURE = 'capture';

    case INHERIT = 'inherit';

    case NULL = 'null';

    case STREAM = 'stream';
}
