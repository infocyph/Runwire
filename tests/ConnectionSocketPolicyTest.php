<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\Enum\DialAttemptPhase;
use Infocyph\Runwire\Network\Internal\DialAttemptLifecycle;

it('applies an explicit outbound TCP_NODELAY policy without an alternate connection engine', function (): void {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $message);
    if (!is_resource($listener)) {
        throw new RuntimeException($message);
    }
    $address = stream_socket_get_name($listener, false);
    expect($address)->toBeString();

    try {
        foreach ([true, false] as $enabled) {
            $client = stream_socket_client('tcp://' . $address, $errorCode, $message, 1.0);
            expect($client)->toBeResource();
            $server = stream_socket_accept($listener, 1.0);
            expect($server)->toBeResource();

            $attempt = new DialAttemptLifecycle();
            $attempt->transition(DialAttemptPhase::CONNECTING);
            $attempt->transition(DialAttemptPhase::READY);
            $loop = new SelectLoop();
            if (!function_exists('socket_import_stream') || !defined('TCP_NODELAY')) {
                expect(fn() => ConnectionFactory::dialed(
                    $attempt, $loop, $client, tcpTransport: true, tcpNoDelayOverride: $enabled,
                ))->toThrow(RuntimeException::class);
            } else {
                $connection = ConnectionFactory::dialed(
                    $attempt, $loop, $client, tcpTransport: true, tcpNoDelayOverride: $enabled,
                );
                $socket = socket_import_stream($client);
                expect($socket)->not->toBeFalse();
                expect(socket_get_option($socket, SOL_TCP, TCP_NODELAY))->toBe((int) $enabled)
                    ->and($attempt->phase())->toBe(DialAttemptPhase::HANDED_OFF);
                $connection->abort();
            }
            fclose($server);
        }
    } finally {
        fclose($listener);
    }
});
