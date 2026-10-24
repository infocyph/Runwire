<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Enum;

/**
 * Selects automatic or fixed protocol scheduling behavior.
 */
enum AdaptivePolicyMode: string
{
    case AUTO = 'auto';

    case FIXED = 'fixed';

    case LATENCY = 'latency';

    case THROUGHPUT = 'throughput';
}
