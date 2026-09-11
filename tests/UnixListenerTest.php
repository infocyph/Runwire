<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\ListenerException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\UnixListener;
use Infocyph\Runwire\Network\UnixListenerOptions;

it('accepts Unix-domain stream connections and removes its owned socket path', function (): void {
    $path = sys_get_temp_dir() . '/runwire-' . bin2hex(random_bytes(6)) . '.sock';
    $listener = UnixListener::bind($path);
    $loop = new SelectLoop();

    $listener->start($loop, static function (Connection $connection) use ($loop): void {
        $connection->onData(static function (Connection $connection) use ($loop): void {
            $connection->write('echo:' . $connection->read());
            $connection->closeGracefully();
            $loop->delay(0.02, static fn () => $loop->stop());
        });
    });

    $client = stream_socket_client('unix://' . $path, $errno, $error, 1.0);
    if (!is_resource($client)) {
        $listener->close();
        throw new RuntimeException(sprintf('Unable to create Unix client: %s (%d).', $error, $errno));
    }
    fwrite($client, 'ok');
    $loop->delay(0.5, static fn () => $loop->stop());
    $loop->run();
    stream_set_timeout($client, 1);
    $reply = fread($client, 7);
    fclose($client);
    $listener->close();

    expect($reply)->toBe('echo:ok')->and(file_exists($path))->toBeFalse();
});

it('never removes an ordinary file when stale-socket cleanup is requested', function (): void {
    $path = sys_get_temp_dir() . '/runwire-' . bin2hex(random_bytes(6)) . '.sock';
    file_put_contents($path, 'keep');

    try {
        expect(fn () => UnixListener::bind(
            $path,
            new UnixListenerOptions(removeStaleSocket: true),
        ))->toThrow(ListenerException::class);
        expect(file_get_contents($path))->toBe('keep');
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});
