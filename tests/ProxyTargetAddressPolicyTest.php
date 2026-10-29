<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\OutboundAddressPolicy;
use Infocyph\Runwire\Network\Proxy\HttpConnectHandshake;
use Infocyph\Runwire\Network\Proxy\HttpConnectTunnel;
use Infocyph\Runwire\Network\Proxy\Socks5Handshake;
use Infocyph\Runwire\Network\Proxy\Socks5Tunnel;

it('rejects proxy-mediated non-public target IPs before sending bytes', function (string $target): void {
    foreach (['http', 'socks5'] as $protocol) {
        [$stream, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $loop = new SelectLoop();
        $connection = ConnectionFactory::accepted($loop, $stream);
        $policy = new OutboundAddressPolicy();
        $tunnel = $protocol === 'http'
            ? new HttpConnectTunnel(
                $loop, $connection, new HttpConnectHandshake(), $target, 443,
                static function (): void {},
                targetAddressPolicy: $policy,
            )
            : new Socks5Tunnel(
                $loop, $connection, new Socks5Handshake(), $target, 443,
                static function (): void {},
                targetAddressPolicy: $policy,
            );
        try {
            expect(fn() => $tunnel->start())->toThrow(RuntimeException::class, 'non-public IP');
            stream_set_blocking($peer, false);
            expect(fread($peer, 128))->toBe('')
                ->and($loop->diagnostics()->timersActive)->toBe(0);
        } finally {
            $connection->abort();
            fclose($peer);
        }
    }
})->with(['127.0.0.1', '100.64.0.1', '198.18.0.1', '192.0.2.1', '224.0.0.1', '2001:db8::1', 'ff02::1']);

it('refuses policy bypass through an unresolved target behind an allowed proxy IP', function (): void {
    foreach (['http', 'socks5'] as $protocol) {
        [$stream, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $loop = new SelectLoop();
        $connection = ConnectionFactory::accepted($loop, $stream);
        $tunnel = $protocol === 'http'
            ? new HttpConnectTunnel(
                $loop, $connection, new HttpConnectHandshake(), 'internal.example', 443,
                static function (): void {},
                targetAddressPolicy: new OutboundAddressPolicy(),
            )
            : new Socks5Tunnel(
                $loop, $connection, new Socks5Handshake(remoteDns: true), 'internal.example', 443,
                static function (): void {},
                targetAddressPolicy: new OutboundAddressPolicy(),
            );
        try {
            expect(fn() => $tunnel->start())->toThrow(RuntimeException::class, 'pre-resolved numeric');
            stream_set_blocking($peer, false);
            expect(fread($peer, 128))->toBe('');
        } finally {
            $connection->abort();
            fclose($peer);
        }
    }
});

it('allows approved public numeric proxy targets while preserving finite cancellation', function (): void {
    foreach (['http', 'socks5'] as $protocol) {
        [$stream, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $loop = new SelectLoop();
        $connection = ConnectionFactory::accepted($loop, $stream);
        $tunnel = $protocol === 'http'
            ? new HttpConnectTunnel(
                $loop, $connection, new HttpConnectHandshake(), '1.1.1.1', 443,
                static function (): void {},
                targetAddressPolicy: new OutboundAddressPolicy(),
            )
            : new Socks5Tunnel(
                $loop, $connection, new Socks5Handshake(), '1.1.1.1', 443,
                static function (): void {},
                targetAddressPolicy: new OutboundAddressPolicy(),
            );
        try {
            $tunnel->start();
            expect($tunnel->cancel())->toBeTrue()
                ->and($loop->diagnostics()->readWatchers)->toBe(0)
                ->and($loop->diagnostics()->writeWatchers)->toBe(0);
        } finally {
            $connection->abort();
            fclose($peer);
        }
    }
});
