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

/** @return array{SelectLoop,ProcessExecutor,Dialer,HappyEyeballsDialer,\Infocyph\Runwire\Network\TcpListener,PooledHttp1Session,ClientProtocolPolicy,string} */
function pooledH1AdmissionFixture(): array
{
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $listener = \Infocyph\Runwire\Network\TcpListener::bind('127.0.0.1:0');
    $listener->start($loop, static function (\Infocyph\Runwire\Network\Connection $connection): void {
        $connection->onData(static function (\Infocyph\Runwire\Network\Connection $owner): void { $owner->read(); });
    });
    $origin = 'http://' . $listener->address();
    $session = new PooledHttp1Session($loop, $dialer, $origin, 'admission-probe', maxConnections: 1);
    $policy = new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true);
    return [$loop, $executor, $numeric, $dialer, $listener, $session, $policy, $origin];
}

it('expires a queued H1 request at its earliest request or inherited deadline', function (bool $inherited): void {
    [$loop, $executor, $numeric, $dialer, $listener, $session, $policy, $origin] = pooledH1AdmissionFixture();
    $deadline = \Infocyph\Runwire\RequestDeadline::afterSeconds(0.02, hrtime(true));
    $parent = new \Infocyph\Runwire\CancellationSource($inherited ? $deadline : null);
    $completed = [];
    $watchdog = $loop->delay(0.5, static fn() => $loop->stop());
    try {
        $first = $session->send(new ClientRequest('GET', $origin . '/held', protocol: $policy),
            static function (): void {}, static function (): void {}, static function (): void {},
        );
        $second = $session->send(new ClientRequest('GET', $origin . '/deadline', protocol: $policy,
            cancellation: $parent->token(), deadline: $inherited ? \Infocyph\Runwire\RequestDeadline::afterSeconds(1, hrtime(true)) : $deadline,
        ), static function (): void {}, static function (): void {}, static function ($error) use (&$completed, $loop): void {
            $completed[] = $error;
            $loop->stop();
        });
        expect($session->metrics()['waiting'])->toBe(1);
        $loop->run();
        expect($completed)->toHaveCount(1)
            ->and($completed[0])->toBeInstanceOf(\Infocyph\Runwire\Exception\CancelledException::class)
            ->and($completed[0]->reason)->toBe(\Infocyph\Runwire\Runtime\Enum\CancellationReason::DEADLINE_EXCEEDED)
            ->and($second->settled())->toBeTrue()
            ->and($second->cancel())->toBeFalse()
            ->and($session->metrics()['waiting'])->toBe(0)
            ->and($parent->token()->subscriptionCount())->toBe(0);
        $first->cancel();
    } finally {
        $session->close();
        $listener->abortConnections();
        $listener->close();
        $dialer->close();
        $numeric->close();
        $executor->close();
        $parent->dispose();
        $loop->cancel($watchdog);
    }
    expect($loop->diagnostics()->timersActive)->toBe(0)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0);
})->with([false, true]);

it('settles already expired pooled H1 work without dialing or retaining admission', function (): void {
    [$loop, $executor, $numeric, $dialer, $listener, $session, $policy, $origin] = pooledH1AdmissionFixture();
    $errors = [];
    try {
        $handle = $session->send(new ClientRequest('GET', $origin . '/', protocol: $policy,
            deadline: new \Infocyph\Runwire\RequestDeadline(0),
        ), static function (): void {}, static function (): void {}, static function ($error) use (&$errors): void { $errors[] = $error; });
        expect($errors)->toHaveCount(1)
            ->and($errors[0])->toBeInstanceOf(\Infocyph\Runwire\Exception\CancelledException::class)
            ->and($errors[0]->reason)->toBe(\Infocyph\Runwire\Runtime\Enum\CancellationReason::DEADLINE_EXCEEDED)
            ->and($handle->settled())->toBeTrue()
            ->and($session->metrics()['active'])->toBe(0)
            ->and($session->metrics()['waiting'])->toBe(0)
            ->and($numeric->pendingCount())->toBe(0);
    } finally {
        $session->close();
        $listener->close();
        $dialer->close();
        $numeric->close();
        $executor->close();
    }
    expect($loop->diagnostics()->timersActive)->toBe(0);
});

it('cleans queued H1 deadlines when cancellation grant or close wins admission', function (string $action): void {
    [$loop, $executor, $numeric, $dialer, $listener, $session, $policy, $origin] = pooledH1AdmissionFixture();
    $source = new \Infocyph\Runwire\CancellationSource();
    $calls = 0;
    $watchdog = $loop->delay(0.5, static fn() => $loop->stop());
    try {
        $first = $session->send(new ClientRequest('GET', $origin . '/held', protocol: $policy),
            static function (): void {}, static function (): void {}, static function (): void {},
        );
        $second = $session->send(new ClientRequest('GET', $origin . '/queued', protocol: $policy,
            cancellation: $source->token(), deadline: \Infocyph\Runwire\RequestDeadline::afterSeconds(0.04, hrtime(true)),
        ), static function (): void {}, static function (): void {}, static function ($error) use (&$calls, $loop): void {
            expect($error)->toBeInstanceOf(Throwable::class);
            ++$calls;
            $loop->stop();
        });
        $loop->delay(0.01, static function () use ($action, $first, $second, $session): void {
            match ($action) {
                'cancel' => $second->cancel(),
                'grant' => $first->cancel(),
                'close' => $session->close(),
            };
        });
        $loop->run();
        expect($calls)->toBe(1)->and($second->settled())->toBeTrue()
            ->and($session->metrics()['waiting'])->toBe(0)
            ->and($source->token()->subscriptionCount())->toBe(0);
    } finally {
        $session->close();
        $listener->abortConnections();
        $listener->close();
        $dialer->close();
        $numeric->close();
        $executor->close();
        $source->dispose();
        $loop->cancel($watchdog);
    }
    expect($loop->diagnostics()->timersActive)->toBe(0)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0);
})->with(['cancel', 'grant', 'close']);

it('normalizes default and explicit IPv6 session TLS peers before connection admission', function (bool $explicit): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $listener = \Infocyph\Runwire\Network\TcpListener::bind('[::1]:0');
    $origin = 'https://' . $listener->address();
    $options = $explicit ? new \Infocyph\Runwire\Network\DialOptions(tls: new \Infocyph\Runwire\Network\TlsClientOptions('::1', alpnProtocols: ['http/1.1'])) : null;
    $session = new PooledHttp1Session($loop, $dialer, $origin, 'ipv6', $options, 'trust-v1');
    $policy = new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1]);
    $errors = [];
    try {
        $handle = $session->send(new ClientRequest('GET', $origin . '/', protocol: $policy),
            static function (): void {}, static function (): void {},
            static function ($error) use (&$errors): void { $errors[] = $error; },
        );
        expect($errors)->toBe([])->and($handle->settled())->toBeFalse()->and($numeric->pendingCount())->toBe(1);
        expect($handle->cancel())->toBeTrue()->and($handle->settled())->toBeTrue()
            ->and($errors)->toHaveCount(1)->and($numeric->pendingCount())->toBe(0);
    } finally {
        $session->close();
        $listener->close();
        $dialer->close();
        $numeric->close();
        $executor->close();
    }
    expect($loop->diagnostics()->timersActive)->toBe(0);
})->with([false, true]);

it('verifies an IPv6 IP-SAN TLS peer and retains the bracketed Host through pooled reuse', function (): void {
    $directory = sys_get_temp_dir() . '/runwire-pooled-ipv6-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $config = $directory . '/openssl.cnf';
    $certificate = $directory . '/cert.pem';
    $privateKey = $directory . '/key.pem';
    file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=IP:::1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n");
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'unrelated.example'], $key, ['config' => $config]);
    $cert = openssl_csr_sign($csr, null, $key, 1, ['config' => $config, 'x509_extensions' => 'v3']);
    expect($key)->not->toBeFalse()->and($csr)->not->toBeFalse()->and($cert)->not->toBeFalse();
    openssl_x509_export($cert, $pem);
    openssl_pkey_export($key, $keyPem);
    file_put_contents($certificate, $pem);
    file_put_contents($privateKey, $keyPem);

    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $listener = \Infocyph\Runwire\Network\TcpListener::bind('[::1]:0', tls: new \Infocyph\Runwire\Network\TlsOptions(
        $certificate, $privateKey, alpnProtocols: ['http/1.1'],
    ));
    $hosts = [];
    $listener->start($loop, static function (\Infocyph\Runwire\Network\Connection $connection) use (&$hosts): void {
        $buffer = '';
        $connection->onData(static function ($owner) use (&$buffer, &$hosts): void {
            $buffer .= $owner->read();
            if (!str_contains($buffer, "\r\n\r\n")) {
                return;
            }
            foreach (explode("\r\n", $buffer) as $line) {
                if (str_starts_with($line, 'Host: ')) {
                    $hosts[] = substr($line, 6);
                }
            }
            $buffer = '';
            $owner->write("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok");
        });
    });
    $origin = 'https://' . $listener->address();
    $options = new \Infocyph\Runwire\Network\DialOptions(tls: new \Infocyph\Runwire\Network\TlsClientOptions(
        '::1', caFile: $certificate, alpnProtocols: ['http/1.1'],
    ));
    $session = new PooledHttp1Session($loop, $dialer, $origin, 'ipv6-peer', $options, 'ip-san-v1');
    $policy = new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1]);
    $results = [];
    $body = '';
    $send = null;
    $send = static function () use ($session, $origin, $policy, &$send, &$results, &$body, $loop): void {
        $session->send(new ClientRequest('GET', $origin . '/resource', protocol: $policy),
            static function (): void {}, static function ($bytes) use (&$body): void { $body .= $bytes; },
            static function ($error) use (&$results, &$send, $loop): void {
                $results[] = $error;
                if ($error === null && count($results) === 1) {
                    $send();
                } else {
                    $loop->stop();
                }
            },
        );
    };
    $watchdog = $loop->delay(2, static fn() => $loop->stop());
    try {
        expect(fn() => new PooledHttp1Session($loop, $dialer, $origin, 'wrong-peer', new \Infocyph\Runwire\Network\DialOptions(
            tls: new \Infocyph\Runwire\Network\TlsClientOptions('other.example', caFile: $certificate, alpnProtocols: ['http/1.1']),
        ), 'trust'))->toThrow(InvalidArgumentException::class);
        expect(fn() => $session->send(new ClientRequest('GET', 'https://[::2]/', protocol: $policy),
            static function (): void {}, static function (): void {}, static function (): void {},
        ))->toThrow(InvalidArgumentException::class);
        $send();
        $loop->run();
        expect($results)->toBe([null, null])->and($body)->toBe('okok')
            ->and($hosts)->toBe([$listener->address(), $listener->address()])
            ->and($session->metrics()['reuse_hits'])->toBe(1)
            ->and($listener->acceptedConnections())->toBe(1);
    } finally {
        $session->close();
        $listener->abortConnections();
        $listener->close();
        $dialer->close();
        $numeric->close();
        $executor->close();
        $loop->cancel($watchdog);
        unlink($privateKey);
        unlink($certificate);
        unlink($config);
        rmdir($directory);
    }
    expect($loop->diagnostics()->timersActive)->toBe(0)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0);
});
