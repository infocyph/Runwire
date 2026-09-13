<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Metrics\DiagnosticsPolicy;
use Infocyph\Runwire\Supervisor\WorkerContext;

final class NativeDatagramWorker
{
    public static function run(
        WorkerContext $context,
        BoundDatagramServer $bound,
        DiagnosticsPolicy $diagnostics = new DiagnosticsPolicy(),
    ): void {
        $loop = new SelectLoop($diagnostics->callbackOverrunSeconds);
        $handler = $bound->definition->handlerFor($context);
        $bound->listener->start($loop, $handler);

        $loop->onReadable($context->stopStream(), function () use ($context, $bound, $loop): void {
            $context->consumeStopWake();
            $bound->listener->close();
            $loop->stop();
        });

        $context->ready();
        $loop->run();
        $bound->listener->close();
    }
}
