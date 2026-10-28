<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\HttpOutboundDispatcher;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\HappyEyeballsDialer;

it('exposes a single strict H1/H2/H3 dispatch policy without launching a forbidden dial', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $dispatcher = new HttpOutboundDispatcher($loop, $dialer);
    $h3 = new ClientProtocolPolicy(ProtocolVersion::HTTP_3, [ProtocolVersion::HTTP_3]);
    $mixed = new ClientProtocolPolicy(ProtocolVersion::HTTP_2, [ProtocolVersion::HTTP_2, ProtocolVersion::HTTP_3], allowFallback: true);
    try {
        expect(fn() => $dispatcher->send(new ClientRequest('GET', 'https://example.test/', protocol: $h3),
            static function (): void {}, static function (): void {}, static function (): void {}))
            ->toThrow(RuntimeUnavailableException::class, 'QUIC');
        expect(fn() => $dispatcher->send(new ClientRequest('GET', 'https://example.test/', protocol: $mixed),
            static function (): void {}, static function (): void {}, static function (): void {}))
            ->toThrow(RuntimeUnavailableException::class, 'downgrade');
        expect($dialer->pendingCount())->toBe(0)
            ->and($numeric->pendingCount())->toBe(0)
            ->and($executor->diagnostics()['submitted'])->toBe(0);
    } finally {
        $dialer->close();
        $numeric->close();
        $executor->close();
    }
});

it('streams one real independent plaintext HTTP peer with owned completion and no socket leak', function (): void {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
    expect($listener)->toBeResource();
    stream_set_blocking($listener, false);
    $address = stream_socket_get_name($listener, false);
    expect($address)->toBeString();
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $dispatcher = new HttpOutboundDispatcher($loop, $dialer);
    $headers = null;
    $body = '';
    $failure = null;
    $watchers = [];
    $watchers[] = $loop->onReadable($listener, static function () use ($loop, $listener, &$watchers): void {
        $socket = stream_socket_accept($listener, 0);
        if (!is_resource($socket)) {
            return;
        }
        stream_set_blocking($socket, false);
        $watchers[] = $loop->onReadable($socket, static function ($stream, int $watcher) use ($loop): void {
            $request = fread($stream, 4096);
            if (is_string($request) && str_contains($request, "\r\n\r\n")) {
                $loop->cancel($watcher);
                fwrite($stream, "HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ntest");
            }
        });
    });
    $watchdog = $loop->delay(2.0, static fn() => $loop->stop());
    try {
        $handle = $dispatcher->send(
            new ClientRequest('GET', 'http://' . $address . '/test',
                protocol: new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true)),
            static function (int $status) use (&$headers): void { $headers = $status; },
            static function (string $chunk) use (&$body): void { $body .= $chunk; },
            static function (?Throwable $error) use (&$failure, $loop): void {
                $failure = $error;
                $loop->stop();
            },
        );
        $loop->run();
        expect($headers)->toBe(200)
            ->and($body)->toBe('test')
            ->and($failure)->toBeNull()
            ->and($handle->settled())->toBeTrue()
            ->and($handle->cancel())->toBeFalse();
    } finally {
        foreach ($watchers as $watcher) {
            $loop->cancel($watcher);
        }
        $loop->cancel($watchdog);
        $dialer->close();
        $numeric->close();
        $executor->close();
        fclose($listener);
    }
});


it('uses an explicitly injected same-origin H1 pool and keeps forbidden origins isolated', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $session = new \Infocyph\Runwire\Http\Client\PooledHttp1Session(
        $loop, $dialer, 'http://127.0.0.1:8899', 'isolated',
    );
    $client = new HttpOutboundDispatcher($loop, $dialer, h1Session: $session);
    $strict = new ClientProtocolPolicy(
        ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true,
    );
    try {
        expect(fn() => $client->send(
            new ClientRequest('GET', 'http://127.0.0.1:8900/', protocol: $strict),
            static function (): void {}, static function (): void {}, static function (): void {},
        ))->toThrow(InvalidArgumentException::class);
        expect(fn() => $client->send(
            new ClientRequest('GET', 'http://127.0.0.1:8899/', protocol: $strict),
            static function (): void {}, static function (): void {}, static function (): void {},
            new DialOptions(),
        ))->toThrow(InvalidArgumentException::class, 'fixed by its session');
        expect($session->metrics()['active'])->toBe(0)
            ->and($session->metrics()['waiting'])->toBe(0)
            ->and($executor->diagnostics()['submitted'])->toBe(0);
    } finally {
        $session->close();
        $dialer->close();
        $numeric->close();
        $executor->close();
    }
});
