<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\PooledHttp1Session;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\HappyEyeballsDialer;

it('rejects origin isolation and opportunistic H2/H3 pooling before dialing', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $policy = new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true);
    $session = new PooledHttp1Session($loop, $dialer, 'http://localhost:8080', 'scope-A');
    try {
        foreach (['http://other.invalid:8080/', 'http://localhost:8081/'] as $bad) {
            expect(fn() => $session->send(new ClientRequest('GET', $bad, protocol: $policy),
                static function (): void {}, static function (): void {}, static function (): void {}))
                ->toThrow(InvalidArgumentException::class);
        }
        expect(fn() => $session->send(new ClientRequest('GET', 'http://localhost:8080/'),
            static function (): void {}, static function (): void {}, static function (): void {}))
            ->toThrow(InvalidArgumentException::class);
        expect($executor->diagnostics()['submitted'])->toBe(0);
    } finally {
        $session->close();
        $dialer->close();
        $numeric->close();
        $executor->close();
    }
});

it('reuses the same real keep-alive H1 socket for two requests and frees all leases', function (): void {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($listener)->toBeResource();
    stream_set_blocking($listener, false);
    $origin = 'http://' . stream_socket_get_name($listener, false);
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $session = new PooledHttp1Session($loop, $dialer, $origin, 'test-scope');
    $policy = new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true);
    $accepted = 0;
    $served = 0;
    $sockets = [];
    $watchers = [];
    $watchers[] = $loop->onReadable($listener, static function () use ($listener, $loop, &$accepted, &$sockets, &$watchers, &$served): void {
        $client = stream_socket_accept($listener, 0);
        if (!is_resource($client)) {
            return;
        }
        ++$accepted;
        stream_set_blocking($client, false);
        $sockets[] = $client;
        $watchers[] = $loop->onReadable($client, static function ($stream) use (&$served): void {
            $incoming = fread($stream, 8192);
            if (is_string($incoming) && str_contains($incoming, "\r\n\r\n")) {
                ++$served;
                fwrite($stream, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nok");
            }
        });
    });
    $done = [];
    $start = static function (string $path) use ($session, $origin, $policy, &$done, $loop): void {
        $session->send(
            new ClientRequest('GET', $origin . $path, protocol: $policy),
            static function (): void {},
            static function (): void {},
            static function (?Throwable $err) use (&$done, $loop): void {
                $done[] = $err;
                if (count($done) === 2) {
                    $loop->stop();
                }
            },
        );
    };
    $watchdog = $loop->delay(2.0, static fn() => $loop->stop());
    $sentSecond = false;
    $poll = $loop->repeat(0.02, static function () use (&$done, $start, &$sentSecond): void {
        if (!$sentSecond && count($done) === 1) {
            $sentSecond = true;
            $start('/two');
        }
    });
    try {
        $start('/one');
        $loop->run();
        expect($done)->toHaveCount(2)
            ->and($done[0])->toBeNull()
            ->and($done[1])->toBeNull()
            ->and($accepted)->toBe(1)
            ->and($served)->toBe(2)
            ->and($session->metrics()['reuse_hits'])->toBe(1)
            ->and($session->metrics()['active'])->toBe(0);
    } finally {
        $session->close();
        foreach ($watchers as $watcher) {
            $loop->cancel($watcher);
        }
        $loop->cancel($watchdog);
        $loop->cancel($poll);
        foreach ($sockets as $socket) {
            fclose($socket);
        }
        $dialer->close();
        $numeric->close();
        $executor->close();
        fclose($listener);
    }
});


it('immediately cancels a queued same-origin lease without waiting for pool timeout', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $session = new PooledHttp1Session($loop, $dialer, 'http://127.0.0.1:8098', 'tenant', maxConnections: 1);
    $policy = new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true);
    $errors = [];
    try {
        $first = $session->send(
            new ClientRequest('GET', 'http://127.0.0.1:8098/one', protocol: $policy),
            static function (): void {}, static function (): void {},
            static function (?Throwable $error) use (&$errors): void { $errors[] = $error; },
        );
        $second = $session->send(
            new ClientRequest('GET', 'http://127.0.0.1:8098/two', protocol: $policy),
            static function (): void {}, static function (): void {},
            static function (?Throwable $error) use (&$errors): void { $errors[] = $error; },
        );
        expect($session->metrics()['active'])->toBe(1)
            ->and($session->metrics()['waiting'])->toBe(1)
            ->and($second->cancel())->toBeTrue()
            ->and($second->settled())->toBeTrue()
            ->and($session->metrics()['waiting'])->toBe(0)
            ->and($errors)->toHaveCount(1)
            ->and($errors[0])->toBeInstanceOf(Throwable::class);
        $first->cancel();
    } finally {
        $session->close();
        $dialer->close();
        $numeric->close();
        $executor->close();
    }
});
