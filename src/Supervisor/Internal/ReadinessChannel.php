<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Loop\LoopInterface;

/**
 * Manages worker readiness watchers, timers, and inherited readiness streams.
 */
final class ReadinessChannel
{
    /**
     * Close a worker readiness channel and cancel its watcher and timeout.
     */
    public static function close(LoopInterface $loop, ChildRecord $record): void
    {
        if ($record->readyWatcherId > 0) {
            $loop->cancel($record->readyWatcherId);
            $record->readyWatcherId = 0;
        }

        self::ready($loop, $record);

        if (is_resource($record->readyStream)) {
            fclose($record->readyStream);
        }

        $record->readyStream = null;
    }

    /**
     * Close readiness streams inherited from existing child records.
     *
     * @param array<int, ChildRecord> $children
     */
    public static function closeInherited(array $children): void
    {
        foreach ($children as $record) {
            if (is_resource($record->readyStream)) {
                fclose($record->readyStream);
            }
        }
    }

    /**
     * Cancel the readiness timeout for a worker that has become ready.
     */
    public static function ready(LoopInterface $loop, ChildRecord $record): void
    {
        if ($record->readyTimerId > 0) {
            $loop->cancel($record->readyTimerId);
            $record->readyTimerId = 0;
        }
    }
}
