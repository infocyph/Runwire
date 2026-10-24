<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

use Infocyph\Runwire\RuntimeContext;

/**
 * Generates request identifiers from runtime context.
 */
interface RequestIdGeneratorInterface
{
    /**
     * Generate a request identifier for the supplied runtime.
     */
    public function generate(RuntimeContext $runtime): string;
}
