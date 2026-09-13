<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

use Infocyph\Runwire\RuntimeContext;

interface RequestIdPolicyInterface
{
    public function resolve(RuntimeContext $runtime, ?string $candidate = null): string;
}
