<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

use Infocyph\Runwire\RuntimeContext;

/**
 * Resolves a final request identifier from runtime context and an optional candidate.
 */
interface RequestIdPolicyInterface
{
    /**
     * Resolve the request identifier to use.
     */
    public function resolve(RuntimeContext $runtime, ?string $candidate = null): string;
}
