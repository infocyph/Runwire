<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\RequestContext;

/**
 * Resets request-scoped application state after request handling completes.
 */
interface RequestResetterInterface
{
    /**
     * Resets state associated with the completed request context.
     */
    public function reset(RequestContext $context): void;
}
