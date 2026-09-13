<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\RuntimeContext;

interface RuntimeApplicationFactoryInterface
{
    public function create(RuntimeContext $context): RuntimeApplicationInterface;
}
