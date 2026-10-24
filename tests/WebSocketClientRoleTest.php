<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\WebSocket\Internal\WebSocketFrameParser;
use Infocyph\Runwire\WebSocket\Internal\WebSocketWireCodec;
use Infocyph\Runwire\WebSocket\WebSocketOptions;
use Infocyph\Runwire\WebSocket\WebSocketSession;

it('reuses one session lifecycle with correct masking when a verified client role is adopted', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray()->toHaveCount(2);
    [$socket, $peer] = $pair;
    stream_set_blocking($peer, false);

    $loop = new SelectLoop();
    $connection = new Connection($loop, $socket, new ConnectionLimits());
    $session = new WebSocketSession(
        $loop,
        $connection,
        new WebSocketOptions(heartbeatIntervalSeconds: 1.0, idleTimeoutSeconds: 2.0),
        clientMode: true,
    );
    $received = [];
    $wire = '';
    $session->onMessage(static function (WebSocketSession $session, $message) use (&$received): void {
        expect($session)->toBeInstanceOf(WebSocketSession::class);
        $received[] = $message->data;
    });
    $watcher = $loop->onReadable($peer, static function ($stream) use (&$wire): void {
        $next = fread($stream, 4_096);
        if (is_string($next)) {
            $wire .= $next;
        }
    });
    $stop = $loop->delay(0.07, static function () use ($loop): void {
        $loop->stop();
    });

    try {
        expect($session->sendText('client-hello')->accepted())->toBeTrue();
        fwrite($peer, WebSocketWireCodec::frame(0x1, 'server-hello'));
        $loop->run();

        $parser = new WebSocketFrameParser(new WebSocketOptions());
        $parser->append($wire);
        $frames = $parser->parse(1);
        expect($received)->toBe(['server-hello'])
            ->and($frames)->toHaveCount(1)
            ->and($frames[0]->payload)->toBe('client-hello');
    } finally {
        $loop->cancel($watcher);
        $loop->cancel($stop);
        $connection->abort();
        fclose($peer);
    }
});
