<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Loop\LoopInterface;

final class ReadinessChannel
{
    public static function close(LoopInterface $loop, ChildRecord $record): void
    {
        if ($record->readyWatcherId > 0) {
            $loop->cancel($record->readyWatcherId);
            $record->readyWatcherId = 0;
        }

        if ($record->readyTimerId > 0) {
            $loop->cancel($record->readyTimerId);
            $record->readyTimerId = 0;
        }

        if (is_resource($record->readyStream)) {
            fclose($record->readyStream);
        }

        $record->readyStream = null;
    }

    /** @param array<int, ChildRecord> $children */
    public static function closeInherited(array $children): void
    {
        foreach ($children as $record) {
            if (is_resource($record->readyStream)) {
                fclose($record->readyStream);
            }
        }
    }
}
