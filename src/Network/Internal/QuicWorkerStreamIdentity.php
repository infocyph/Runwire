<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use RuntimeException;

/** @internal Separate native QUIC wire IDs from worker-local stream handles. */
final readonly class QuicWorkerStreamIdentity
{
    /** @return array{wire_stream_id:int}|array{} */
    public static function fields(object $stream): array
    {
        if (!method_exists($stream, 'getId')) {
            return [];
        }
        $id = $stream->getId();
        if (!is_int($id) || $id < 0) {
            throw new RuntimeException('Native QUIC returned an invalid wire stream identifier.');
        }

        return ['wire_stream_id' => $id];
    }
}
