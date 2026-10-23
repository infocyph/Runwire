<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Enum;

/** The pending outbound connection state before an established stream exists. */
enum DialAttemptPhase: string
{
    case CANCELLED = 'cancelled';

    case CONNECTING = 'connecting';

    case FAILED = 'failed';

    case HANDED_OFF = 'handed_off';

    case HANDSHAKING = 'handshaking';

    case NEW = 'new';

    case READY = 'ready';

    case RESOLVING = 'resolving';
}
