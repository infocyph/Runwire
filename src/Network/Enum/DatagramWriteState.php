<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Enum;

enum DatagramWriteState: string
{
    case CLOSED = 'closed';

    case ERROR = 'error';

    case REJECTED_LIMIT = 'rejected_limit';

    case SENT = 'sent';

    case WOULD_BLOCK = 'would_block';
}
