<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Internal;

/**
 * Describes the current adaptive protocol scheduling state.
 *
 * @internal
 */
enum AdaptiveLoadState: string
{
    case LATENCY = 'latency';
    case BALANCED = 'balanced';
    case THROUGHPUT = 'throughput';
}
