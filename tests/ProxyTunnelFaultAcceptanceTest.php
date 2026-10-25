<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\Proxy\HttpConnectHandshake;
use Infocyph\Runwire\Network\Proxy\HttpConnectTunnel;
use Infocyph\Runwire\Network\Proxy\Socks5Handshake;
use Infocyph\Runwire\Network\Proxy\Socks5Tunnel;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

/**
 * @return array{errors:list<Throwable>,successes:int,timers:int,reads:int,writes:int,remainingSubscriptions:int}
 */
function runwireProxyFaultFixture(
    string $protocol,
    string $failureMode,
): array {
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($sockets)->toBeArray();
    [$socket, $peer] = $sockets;
    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted($loop, $socket);
    $source = new CancellationSource();
    $errors = [];
    $successes = 0;
    $callback = static function ($value, ?Throwable $error) use (&$errors, &$successes, $loop): void {
        if ($error !== null) {
            $errors[] = $error;
        } elseif ($value instanceof \Infocyph\Runwire\Network\Connection) {
            ++$successes;
        } else {
            $errors[] = new RuntimeException('Proxy tunnel returned neither a connection nor an error.');
        }
        $loop->stop();
    };
    $token = $source->token();
    $tunnel = $protocol === 'http'
        ? new HttpConnectTunnel(
            $loop, $connection, new HttpConnectHandshake(), 'target.example', 443,
            $callback, timeoutSeconds: 0.07, token: $token,
        )
        : new Socks5Tunnel(
            $loop, $connection, new Socks5Handshake(), '127.0.0.1', 443,
            $callback, timeoutSeconds: 0.07, token: $token,
        );
    $scriptTimer = null;
    $watchdog = null;
    try {
        $tunnel->start();
        if ($failureMode !== 'timeout') {
            $scriptTimer = $loop->delay(0.01, static function () use (
                $failureMode, $protocol, $peer, $source,
            ): void {
                if ($failureMode === 'cancel') {
                    $source->cancel(CancellationReason::HOST_CANCELLED);
                } elseif ($failureMode === 'eof') {
                    stream_socket_shutdown($peer, STREAM_SHUT_WR);
                } else {
                    fwrite($peer, $protocol === 'http'
                        ? "HTTP/1.1 407 Proxy Authentication Required\r\n\r\n"
                        : "\x05\xff");
                }
            });
        }
        $watchdog = $loop->delay(0.3, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        expect($errors)->toHaveCount(1);
        expect($successes)->toBe(0)
            ->and($tunnel->cancel())->toBeFalse()
            ->and($source->token()->subscriptionCount())->toBe(0);
        if ($failureMode === 'cancel') {
            expect($errors[0])->toBeInstanceOf(CancelledException::class);
        } else {
            expect($errors[0])->toBeInstanceOf(RuntimeException::class);
        }
    } finally {
        if ($scriptTimer !== null) {
            $loop->cancel($scriptTimer);
        }
        if ($watchdog !== null) {
            $loop->cancel($watchdog);
        }
        $connection->abort();
        $source->dispose();
        fclose($peer);
    }

    return [
        'errors' => $errors,
        'successes' => $successes,
        'timers' => $loop->diagnostics()->timersActive,
        'reads' => $loop->diagnostics()->readWatchers,
        'writes' => $loop->diagnostics()->writeWatchers,
        'remainingSubscriptions' => $token->subscriptionCount(),
    ];
}

it('fails closed for HTTP CONNECT and SOCKS5 invalid peer replies without leaking watchers', function (): void {
    foreach (['http', 'socks5'] as $protocol) {
        $outcome = runwireProxyFaultFixture($protocol, 'denied');
        expect($outcome['errors'])->toHaveCount(1)
            ->and($outcome['timers'])->toBe(0)
            ->and($outcome['reads'])->toBe(0)
            ->and($outcome['writes'])->toBe(0)
            ->and($outcome['remainingSubscriptions'])->toBe(0);
    }
});

it('preserves exactly-once cancellation and transport cleanup across both proxy protocols', function (): void {
    foreach (['http', 'socks5'] as $protocol) {
        $outcome = runwireProxyFaultFixture($protocol, 'cancel');
        expect($outcome['errors'])->toHaveCount(1)
            ->and($outcome['errors'][0])->toBeInstanceOf(CancelledException::class)
            ->and($outcome['timers'])->toBe(0)
            ->and($outcome['reads'])->toBe(0)
            ->and($outcome['writes'])->toBe(0)
            ->and($outcome['remainingSubscriptions'])->toBe(0);
    }
});

it('enforces bounded idle handshake deadlines without leaving proxy sockets active', function (): void {
    foreach (['http', 'socks5'] as $protocol) {
        $outcome = runwireProxyFaultFixture($protocol, 'timeout');
        expect($outcome['errors'])->toHaveCount(1)
            ->and($outcome['timers'])->toBe(0)
            ->and($outcome['reads'])->toBe(0)
            ->and($outcome['writes'])->toBe(0);
    }
});

it('does not deliver success or duplicate failures when a proxy closes early', function (): void {
    foreach (['http', 'socks5'] as $protocol) {
        $outcome = runwireProxyFaultFixture($protocol, 'eof');
        expect($outcome['errors'])->toHaveCount(1)
            ->and($outcome['successes'])->toBe(0)
            ->and($outcome['timers'])->toBe(0)
            ->and($outcome['reads'])->toBe(0)
            ->and($outcome['writes'])->toBe(0);
    }
});
