<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\Loop\LoopInterface;

/**
 * Allows a request handler to attach cooperative work to a runtime-owned loop.
 */
interface LoopAwareRequestHandlerInterface
{
    /**
     * Bind the handler to the loop that owns request I/O progress.
     */
    public function attachLoop(LoopInterface $loop): void;
}
