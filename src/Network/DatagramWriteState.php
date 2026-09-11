<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

enum DatagramWriteState: string
{
    case SENT = 'sent';
    case WOULD_BLOCK = 'would_block';
    case REJECTED_LIMIT = 'rejected_limit';
    case CLOSED = 'closed';
    case ERROR = 'error';
}
