<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\ConnectionFactory;
use Infocyph\Runwire\Network\Enum\DialAttemptPhase;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\Internal\DialAttemptLifecycle;

it('adopts real connected TCP streams through the one established connection engine', function (): void {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if (!is_resource($listener)) {
        throw new RuntimeException($errorMessage);
    }
    $address = stream_socket_get_name($listener, false);
    expect($address)->toBeString();
    $client = stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 1.0);
    if (!is_resource($client)) {
        fclose($listener);
        throw new RuntimeException($errorMessage);
    }
    $accepted = stream_socket_accept($listener, 1.0);
    if (!is_resource($accepted)) {
        fclose($client);
        fclose($listener);
        throw new RuntimeException('Could not accept local TCP test peer.');
    }

    $loop = new SelectLoop();
    $connection = ConnectionFactory::accepted(
        $loop,
        $accepted,
        peerAddress: stream_socket_get_name($accepted, true) ?: null,
        localAddress: stream_socket_get_name($accepted, false) ?: null,
        tcpTransport: true,
    );
    expect($connection->state())->toBe(ConnectionState::OPEN);
    fwrite($client, 'factory');
    $loop->delay(0.02, function () use ($loop): void {
        $loop->stop();
    });
    $loop->run();
    expect($connection->read())->toBe('factory')
        ->and($connection->peerAddress())->toBeString()
        ->and($connection->localAddress())->toBeString();
    $connection->abort();
    fclose($client);
    fclose($listener);
});

it('requires a verified dial attempt and transfers established stream ownership only once', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();
    [$outbound, $peer] = $pair;
    $loop = new SelectLoop();
    $attempt = new DialAttemptLifecycle();
    expect(fn() => ConnectionFactory::dialed($attempt, $loop, $outbound))
        ->toThrow(LogicException::class);

    $attempt->transition(DialAttemptPhase::CONNECTING);
    $attempt->transition(DialAttemptPhase::READY);
    $connection = ConnectionFactory::dialed($attempt, $loop, $outbound);
    expect($connection->state())->toBe(ConnectionState::OPEN)
        ->and($attempt->phase())->toBe(DialAttemptPhase::HANDED_OFF);
    expect(fn() => ConnectionFactory::dialed($attempt, $loop, $outbound))
        ->toThrow(LogicException::class);

    fwrite($peer, 'reuse');
    $loop->delay(0.015, function () use ($loop): void {
        $loop->stop();
    });
    $loop->run();
    expect($connection->read())->toBe('reuse');
    $connection->abort();
    fclose($peer);
});
