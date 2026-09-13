<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use Closure;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Throwable;

final class ConnectionCallbackDispatcher
{
    /** @param list<Closure> $callbacks */
    public static function dispatch(array $callbacks, Connection $connection, CloseReason $reason): void
    {
        $firstFailure = null;

        foreach ($callbacks as $callback) {
            try {
                $callback($connection, $reason);
            } catch (Throwable $throwable) {
                $firstFailure ??= $throwable;
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }

    public static function invoke(?Closure $callback, Connection $connection, Closure $onFailure): void
    {
        if ($callback === null) {
            return;
        }

        try {
            $callback($connection);
        } catch (Throwable $throwable) {
            try {
                $onFailure();
            } catch (Throwable) {
                // Preserve the originating callback failure after deterministic cleanup.
            }

            throw $throwable;
        }
    }
}
