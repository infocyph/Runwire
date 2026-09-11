<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeDriver;

final readonly class RuntimeSelection
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public RuntimeDriver $driver,
        public RuntimeCapabilities $capabilities,
        public array $warnings = [],
    ) {}
}
