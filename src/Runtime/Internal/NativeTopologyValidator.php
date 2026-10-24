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
     */
    public static function assertSupported(
        array $servers,
        RuntimeSelection $selection,
        RuntimeOptions $options,
    ): void {
        self::assertHttp3($servers, $selection);

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
     */
    private static function assertHttp3(array $servers, RuntimeSelection $selection): void
    {
        foreach ($servers as $server) {
            if (!$server instanceof Server || $server->http3 === null) {
                continue;
            }

            if (!$selection->capabilities->supportsQuic) {
                throw new RuntimeUnavailableException(sprintf(
                    'Native HTTP/3 was configured for server "%s", but QUIC capability is unavailable.',
                    $server->name,
                ));
            }

            if (
                $selection->capabilities->ownsWorkerPool
                && $selection->capabilities->resources->resolveWorkerCount($server->workers) > 1
                && !$server->listener->reusePort
            ) {
                throw new RuntimeUnavailableException(
                    'Native HTTP/3 with multiple workers requires explicit ListenerOptions::reusePort support.',
                );
            }
        }
    }

    /**
     * @param array<string, Server|StreamServer|DatagramServer> $servers
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
