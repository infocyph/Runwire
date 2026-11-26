<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\DatagramServer;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Runtime\RuntimeSelection;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\StreamServer;

/**
 * Validates capability-sensitive native listener topology before resources are bound.
 */
final class NativeTopologyValidator
{
    /**
     * @param array<string, Server|StreamServer|DatagramServer> $servers
     * @throws \Infocyph\Runwire\Exception\RuntimeUnavailableException
     */
    public static function assertSupported(
        array $servers,
        RuntimeSelection $selection,
        RuntimeOptions $options,
    ): void {
        if ($selection->capabilities->ownsWorkerPool) {
            return;
        }

        self::assertPortableWorkers($servers);

        if ($options->workerRecycle->enabled()) {
            throw new RuntimeUnavailableException(
                'Worker recycle thresholds require native prefork worker-pool capability and are unavailable in portable single-process mode.',
            );
        }
    }

    /**
     * @param array<string, Server|StreamServer|DatagramServer> $servers
     * @throws \Infocyph\Runwire\Exception\RuntimeUnavailableException
     */
    private static function assertPortableWorkers(array $servers): void
    {
        foreach ($servers as $server) {
            if ($server->workers <= 1) {
                continue;
            }

            throw new RuntimeUnavailableException(sprintf(
                'Server "%s" requests %d workers, but the portable native runtime supports only one process.',
                $server->name,
                $server->workers,
            ));
        }
    }
}
