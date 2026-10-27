<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\TlsClientOptions;

it('closes an encrypted proxy connection rather than exposing it as verified origin TLS', function (): void {
    [$stream, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new SelectLoop();
    $proxy = ConnectionFactory::accepted($loop, $stream, encrypted: true);
    $completed = 0;

    try {
        expect(fn() => $proxy->upgradeToVerifiedTls(
            new TlsClientOptions('origin.example'),
            static function () use (&$completed): void {
                ++$completed;
            },
        ))->toThrow(LogicException::class, 'TLS-over-TLS proxy nesting is unavailable');

        expect($proxy->state())->toBe(ConnectionState::CLOSED)
            ->and(is_resource($stream))->toBeFalse()
            ->and($completed)->toBe(0)
            ->and($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $proxy->abort();
        fclose($peer);
    }
});

it('refuses an unowned TLS upgrade while another consumer owns the tunnel', function (): void {
    [$stream, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $loop = new SelectLoop();
    $tunnel = ConnectionFactory::accepted($loop, $stream);
    $owner = new stdClass();

    try {
        $tunnel->claimCallbacks(
            $owner,
            static function (): void {},
            static function (): void {},
            static function (): void {},
        );

        expect(fn() => $tunnel->upgradeToVerifiedTls(
            new TlsClientOptions('origin.example'),
            static function (): void {},
        ))->toThrow(LogicException::class, 'unowned, quiescent cleartext tunnel');

        expect($tunnel->state())->not->toBe(ConnectionState::CLOSED)
            ->and(is_resource($stream))->toBeTrue();
    } finally {
        $tunnel->abort();
        fclose($peer);
    }
});
