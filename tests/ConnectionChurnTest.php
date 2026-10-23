<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\Internal\ByteBudget;

it('does not leak watchers or queued bytes across 96 simultaneous established stream lifecycles', function (): void {
    $loop = new SelectLoop();
    $budget = new ByteBudget(4_096);
    $peers = [];
    $closed = 0;
    $received = 0;

    for ($index = 0; $index < 96; ++$index) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        expect($pair)->toBeArray();
        [$client, $peer] = $pair;
        $connection = ConnectionFactory::accepted($loop, $client, bufferBudget: $budget);
        $connection->onData(function ($stream) use (&$received): void {
            $received += strlen($stream->read());
            $stream->abort();
        });
        $connection->onClose(function () use (&$closed): void {
            ++$closed;
        });
        $peers[] = $peer;
        expect(fwrite($peer, 'x'))->toBe(1);
    }

    $loop->run();
    foreach ($peers as $peer) {
        fclose($peer);
    }
    expect($received)->toBe(96)
        ->and($closed)->toBe(96)
        ->and($budget->used())->toBe(0)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
