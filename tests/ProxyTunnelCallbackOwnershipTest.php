<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\Internal\ConnectionCallbackOwnership;
use Infocyph\Runwire\Network\Proxy\HttpConnectHandshake;
use Infocyph\Runwire\Network\Proxy\HttpConnectTunnel;
use Infocyph\Runwire\Network\Proxy\Socks5Handshake;
use Infocyph\Runwire\Network\Proxy\Socks5Tunnel;

it('exposes an owner-specific callback query without revealing or changing other owners', function (): void {
    $ownership = new ConnectionCallbackOwnership();
    $first = new stdClass();
    $other = new stdClass();
    expect($ownership->ownedBy($first))->toBeFalse();
    $ownership->claim($first, false, false);
    expect($ownership->ownedBy($first))->toBeTrue()
        ->and($ownership->ownedBy($other))->toBeFalse()
        ->and(fn() => $ownership->release($other))->toThrow(LogicException::class);
    $ownership->release($first);
    expect($ownership->unclaimed())->toBeTrue();
});

it('never releases or aborts another owner when either proxy cannot claim callbacks', function (): void {
    foreach (['http', 'socks5'] as $protocol) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        expect($pair)->toBeArray();
        [$stream, $peer] = $pair;
        $loop = new SelectLoop();
        $connection = ConnectionFactory::accepted($loop, $stream);
        $owner = new stdClass();
        $acceptedBytes = '';
        $result = [];
        $onData = static function (Connection $existing) use (&$acceptedBytes, $loop): void {
            $acceptedBytes .= $existing->read();
            $loop->stop();
        };
        $onDrain = static function (Connection $existing): void {
            expect($existing->state())->not->toBe(ConnectionState::CLOSED);
        };
        $connection->claimCallbacks($owner, $onData, $onDrain, $onDrain);
        try {
            $completed = static function (?Connection $ready, ?Throwable $error) use (&$result): void {
                $result[] = [$ready, $error];
            };
            $tunnel = $protocol === 'http'
                ? new HttpConnectTunnel(
                    $loop, $connection, new HttpConnectHandshake(), 'example.com', 443, $completed,
                )
                : new Socks5Tunnel(
                    $loop, $connection, new Socks5Handshake(), '127.0.0.1', 443, $completed,
                );
            $tunnel->start();
            expect($result)->toHaveCount(1)
                ->and($result[0][0])->toBeNull()
                ->and($result[0][1])->toBeInstanceOf(LogicException::class)
                ->and($connection->callbacksOwnedBy($owner))->toBeTrue()
                ->and($connection->callbacksOwnedBy($tunnel))->toBeFalse()
                ->and($connection->state())->not->toBe(ConnectionState::CLOSED)
                ->and($tunnel->cancel())->toBeFalse();
            fwrite($peer, 'original-owner-continues');
            $watchdog = $loop->delay(0.25, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();
            $loop->cancel($watchdog);
            expect($acceptedBytes)->toBe('original-owner-continues')
                ->and($connection->callbacksOwnedBy($owner))->toBeTrue();
        } finally {
            if ($connection->callbacksOwnedBy($owner)) {
                $connection->releaseCallbacks($owner);
            }
            $connection->abort();
            fclose($peer);
        }
        expect($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0);
    }
});
