<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\RequestContext;

interface RequestResetterInterface
{
    public function reset(RequestContext $context): void;
}
