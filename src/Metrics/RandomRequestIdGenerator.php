<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

use Infocyph\Runwire\RuntimeContext;

final readonly class RandomRequestIdGenerator implements RequestIdGeneratorInterface
{
    public function generate(RuntimeContext $runtime): string
    {
        return bin2hex(random_bytes(16));
    }
}
