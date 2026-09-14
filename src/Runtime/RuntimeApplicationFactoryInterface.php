<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\RuntimeContext;

/**
 * Creates runtime applications for a concrete worker or host runtime context.
 */
interface RuntimeApplicationFactoryInterface
{
    /**
     * Creates an application bound to the supplied runtime context.
     */
    public function create(RuntimeContext $context): RuntimeApplicationInterface;
}
