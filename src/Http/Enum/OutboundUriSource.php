<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Enum;

/** Records how the current outbound URI was selected. Network policy is applied separately. */
enum OutboundUriSource: string
{
    case DIRECT = 'direct';

    case PSR_APPROVAL_MATCH = 'psr_approval_match';

    case PSR_REQUEST = 'psr_request';

    case REDIRECT = 'redirect';
}
