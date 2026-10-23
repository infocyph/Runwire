<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\Enum\CloseReason;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\Enum\WriteState;

it('supports a real local half-close while still accepting inbound data', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$client, $peer] = $pair;
    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted($loop, $client);
    $data = [];
    $closes = [];
    $connection->onData(function ($stream) use (&$data): void {
        $data[] = $stream->read();
        $stream->closeGracefully();
    });
    $connection->onClose(function ($stream, CloseReason $reason) use (&$closes): void {
        unset($stream);
        $closes[] = $reason;
    });
    $connection->shutdownWrite();
    $connection->pauseReads();
    expect($connection->isReadPaused())->toBeTrue();
    $connection->resumeReads();
    expect($connection->isReadPaused())->toBeFalse();
    expect($connection->state())->toBe(ConnectionState::HALF_CLOSED)
        ->and($connection->write('prohibited')->state)->toBe(WriteState::CLOSED)
        ->and(fn() => $connection->shutdownWrite())->toThrow(LogicException::class);
    expect(fread($peer, 1))->toBe('');
    fwrite($peer, 'response');
    $loop->delay(0.05, static function () use ($loop): void {
        $loop->stop();
    });
    $loop->run();

    expect($data)->toBe(['response'])
        ->and($connection->state())->toBe(ConnectionState::CLOSED)
        ->and($closes)->toBe([CloseReason::LOCAL_GRACEFUL])
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    $connection->abort();
    fclose($peer);
});

it('rejects local half-close after draining or closing without double-close events', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$client, $peer] = $pair;
    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted($loop, $client);
    $closed = 0;
    $connection->onClose(function () use (&$closed): void {
        ++$closed;
    });
    $connection->closeGracefully();
    expect($connection->state())->toBe(ConnectionState::CLOSED)
        ->and(fn() => $connection->shutdownWrite())->toThrow(LogicException::class);
    $connection->closeGracefully();
    $connection->abort();
    expect($closed)->toBe(1);
    fclose($peer);
});

it('delivers unread bounded bytes to a new protocol callback owner once', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$client, $peer] = $pair;
    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted($loop, $client);
    $old = [];
    $new = [];
    $owner = new stdClass();
    $connection->onData(function ($stream) use (&$old, &$new, $owner): void {
        $old[] = $stream->read(4);
        $stream->handoffCallbacks(
            $owner,
            function ($stream) use (&$new): void {
                $new[] = $stream->read();
            },
            static function (): void {},
            static function (): void {},
        );
    });
    fwrite($peer, 'initrest');
    $loop->delay(0.05, static function () use ($loop): void {
        $loop->stop();
    });
    $loop->run();

    expect($old)->toBe(['init'])
        ->and($new)->toBe(['rest'])
        ->and($connection->receivedBytes())->toBe(0);
    expect(fn() => $connection->releaseCallbacks(new stdClass()))->toThrow(LogicException::class);
    $connection->releaseCallbacks($owner);
    $connection->abort();
    fclose($peer);
    expect($loop->diagnostics()->readWatchers)->toBe(0);
});
