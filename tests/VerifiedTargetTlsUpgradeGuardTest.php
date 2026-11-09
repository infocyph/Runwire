<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\TlsClientOptions;
use LogicException;
use RuntimeException;
use stdClass;


it('closes an encrypted proxy connection rather than exposing it as verified origin TLS', function (): void {
    [$stream, $peer] = runwireVerifiedTargetTlsUpgradeGuardTestSocketPair();
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
    [$stream, $peer] = runwireVerifiedTargetTlsUpgradeGuardTestSocketPair();
    $loop = new SelectLoop();
    $tunnel = ConnectionFactory::accepted($loop, $stream);
    $owner = new stdClass();

    try {
        $unexpected = static function (): void {
            throw new RuntimeException('A forbidden TLS upgrade must not dispatch callbacks.');
        };
        $tunnel->claimCallbacks($owner, $unexpected, $unexpected, $unexpected);

        expect(fn() => $tunnel->upgradeToVerifiedTls(
            new TlsClientOptions('origin.example'),
            $unexpected,
        ))->toThrow(LogicException::class, 'unowned, quiescent cleartext tunnel');

        expect($tunnel->state())->not->toBe(ConnectionState::CLOSED)
            ->and(is_resource($stream))->toBeTrue();
    } finally {
        $tunnel->abort();
        fclose($peer);
    }
});

/**
 * @return array{resource, resource}
 * @throws \RuntimeException
 */
function runwireVerifiedTargetTlsUpgradeGuardTestSocketPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false || !isset($pair[0], $pair[1])) {
        throw new \RuntimeException('Unable to create a complete test socket pair.');
    }

    return [$pair[0], $pair[1]];
}
