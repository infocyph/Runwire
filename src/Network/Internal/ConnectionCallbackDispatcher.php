<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use Closure;
use Infocyph\Runwire\Network\CloseReason;
use Infocyph\Runwire\Network\Connection;
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
}
