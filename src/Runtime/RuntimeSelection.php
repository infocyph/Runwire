<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;

/**
 * Captures a resolved runtime driver, capabilities, and selection warnings.
 */
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
