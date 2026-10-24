<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Enum;

/**
 * Describes the current adaptive protocol scheduling state.
 *
 * @internal
 */
enum AdaptiveLoadState: string
{
    case BALANCED = 'balanced';

    case LATENCY = 'latency';

    case THROUGHPUT = 'throughput';
}
