<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\Enum\DialAttemptPhase;
use Infocyph\Runwire\Network\Internal\DialAttemptLifecycle;

it('applies explicit TCP_NODELAY consistently for adopted and dialed TCP streams', function (): void {
    foreach ([true, false] as $policy) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        if (!is_resource($listener)) {
            throw new RuntimeException($message);
        }
        $address = stream_socket_get_name($listener, false);
        if (!is_string($address)) {
            fclose($listener);
            throw new RuntimeException('Missing TCP listener address.');
        }
        $outbound = stream_socket_client('tcp://' . $address, $errno, $message, 1.0);
        if (!is_resource($outbound)) {
            fclose($listener);
            throw new RuntimeException($message);
        }
        $incoming = stream_socket_accept($listener, 1.0);
        if (!is_resource($incoming)) {
            fclose($outbound);
            fclose($listener);
            throw new RuntimeException('TCP peer did not accept.');
        }

        $supported = function_exists('socket_import_stream')
            && function_exists('socket_get_option')
            && defined('TCP_NODELAY') && defined('SOL_TCP');
        $loop = new SelectLoop();
        $attempt = new DialAttemptLifecycle();
        $attempt->transition(DialAttemptPhase::CONNECTING);
        $attempt->transition(DialAttemptPhase::READY);
        if (!$supported) {
            expect(fn() => ConnectionFactory::dialed(
                $attempt,
                $loop,
                $outbound,
                tcpTransport: true,
                tcpNoDelayOverride: $policy,
            ))->toThrow(RuntimeException::class);
            expect($attempt->phase())->toBe(DialAttemptPhase::FAILED);
            expect(is_resource($outbound))->toBeFalse();
            fclose($incoming);
        } else {
            $dialed = ConnectionFactory::dialed(
                $attempt,
                $loop,
                $outbound,
                tcpTransport: true,
                tcpNoDelayOverride: $policy,
            );
            $accepted = ConnectionFactory::accepted(
                $loop,
                $incoming,
                tcpTransport: true,
                tcpNoDelayOverride: !$policy,
            );
            $dialedSocket = socket_import_stream($outbound);
            $acceptedSocket = socket_import_stream($incoming);
            expect($dialedSocket)->not->toBeFalse()
                ->and($acceptedSocket)->not->toBeFalse()
                ->and(socket_get_option($dialedSocket, SOL_TCP, TCP_NODELAY))->toBe((int) $policy)
                ->and(socket_get_option($acceptedSocket, SOL_TCP, TCP_NODELAY))->toBe((int) !$policy);

            $dialed->applyTcpNoDelayDefault(!$policy);
            $accepted->applyTcpNoDelayDefault($policy);
            expect(socket_get_option($dialedSocket, SOL_TCP, TCP_NODELAY))->toBe((int) $policy)
                ->and(socket_get_option($acceptedSocket, SOL_TCP, TCP_NODELAY))->toBe((int) !$policy);
            $dialed->abort();
            $accepted->abort();
        }
        fclose($listener);
        expect($loop->diagnostics()->readWatchers)->toBe(0);
    }
});

it('does not treat Unix-domain streams as TCP sockets for optional tuning', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$stream, $peer] = $pair;
    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted(
        $loop,
        $stream,
        tcpTransport: false,
        tcpNoDelayOverride: true,
    );
    $connection->applyTcpNoDelayDefault(true);
    $connection->abort();
    fclose($peer);
    expect($loop->diagnostics()->readWatchers)->toBe(0);
});
