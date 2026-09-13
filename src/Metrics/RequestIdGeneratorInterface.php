<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

use Infocyph\Runwire\RuntimeContext;

interface RequestIdGeneratorInterface
{
    public function generate(RuntimeContext $runtime): string;
}
