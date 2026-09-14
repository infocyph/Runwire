<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics\Enum;

/**
 * Classifies application and transport failures for runtime metrics.
 */
enum ApplicationErrorClass: string
{
    case BOOT_FAILURE = 'boot_failure';

    case CLIENT_CANCELLED = 'client_cancelled';

    case DEADLINE_EXCEEDED = 'deadline_exceeded';

    case HANDLER_EXCEPTION = 'handler_exception';

    case OVERLOAD_REJECTION = 'overload_rejection';

    case PROTOCOL_ERROR = 'protocol_error';

    case RESETTER_FAILURE = 'resetter_failure';

    case TRANSPORT_ERROR = 'transport_error';

    case WARMUP_FAILURE = 'warmup_failure';
}
