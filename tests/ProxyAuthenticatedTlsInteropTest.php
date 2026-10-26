<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Network\Proxy\HttpConnectHandshake;
use Infocyph\Runwire\Network\Proxy\HttpConnectTunnel;
use Infocyph\Runwire\Network\Proxy\Socks5Handshake;
use Infocyph\Runwire\Network\Proxy\Socks5Tunnel;
use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Network\TlsClientOptions;
use Infocyph\Runwire\Network\TlsOptions;

/**
 * Exercise real nonblocking TLS client/proxy handshakes and authenticated
 * CONNECT/SOCKS5 negotiation without assuming nested original-target TLS.
 *
 * @return array{received:string,verified:?string,error:?Throwable,completed:int,accepted:bool}
 */
function runwireAuthenticatedProxyInterop(string $protocol, string $expectedProxy = 'localhost', bool $rejectAuthentication = false): array
{
    $dir = sys_get_temp_dir() . '/runwire-proxy-tls-' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700)) {
        throw new RuntimeException('Cannot prepare isolated proxy TLS fixtures.');
    }
    $key = openssl_pkey_new(['private_key_bits' => 2_048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    expect($key)->not->toBeFalse()->and($csr)->not->toBeFalse()->and($cert)->not->toBeFalse();
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem);
    $caFile = $dir . '/cert.pem';
    $keyFile = $dir . '/key.pem';
    file_put_contents($caFile, $certPem);
    file_put_contents($keyFile, $keyPem);

    $loop = new SelectLoop();
    $listener = TcpListener::bind(
        '127.0.0.1:0',
        new ListenerOptions(maxConnections: 4),
        new ConnectionLimits(),
        new TlsOptions($caFile, $keyFile, alpnProtocols: ['http/1.1']),
    );
    $dialer = new Dialer($loop);
    $client = null;
    $received = '';
    $verified = null;
    $failure = null;
    $completed = 0;
    $accepted = false;
    $expired = false;
    $watchdog = $loop->delay(3, static function () use (&$expired, $loop): void {
        $expired = true;
        $loop->stop();
    });
    try {
        $listener->start($loop, static function (Connection $peer) use (
            &$received, &$accepted, $protocol, $rejectAuthentication,
        ): void {
            $accepted = true;
            $state = 0;
            $pending = '';
            $peer->onData(static function (Connection $ready) use (
                &$received, &$state, &$pending, $protocol, $rejectAuthentication,
            ): void {
                $pending .= $ready->read();
                if ($protocol === 'http') {
                    if (!str_contains($pending, "\r\n\r\n")) {
                        return;
                    }
                    $received = $pending;
                    $pending = '';
                    $ready->write("HTTP/1.1 200 Connection Established\r\n\r\n");

                    return;
                }
                if ($state === 0 && strlen($pending) >= 3) {
                    expect($pending)->toBe("\x05\x01\x02");
                    $pending = '';
                    $state = 1;
                    $ready->write("\x05\x02");

                    return;
                }
                if ($state === 1 && strlen($pending) >= 13) {
                    expect($pending)->toBe("\x01\x04user\x06secret");
                    $pending = '';
                    $state = $rejectAuthentication ? 3 : 2;
                    $ready->write($rejectAuthentication ? "\x01\x01" : "\x01\x00");

                    return;
                }
                if ($state === 2 && strlen($pending) >= 10) {
                    $received = $pending;
                    $pending = '';
                    $state = 3;
                    $ready->write("\x05\x00\x00\x01\x7f\x00\x00\x01\x01\xbb");
                } elseif ($state === 3 && $pending !== '') {
                    $received = $pending;
                }
            });
        });
        $dialer->connect(
            'tcp://' . $listener->address(),
            static function (?Connection $ready, ?Throwable $error) use (
                &$client, &$verified, &$failure, &$completed, $loop, $protocol, $expectedProxy,
            ): void {
                if ($error !== null || $ready === null) {
                    $failure = $error ?? new RuntimeException('Authenticated proxy TLS dial failed.');
                    $loop->stop();

                    return;
                }
                $client = $ready;
                $verified = \Infocyph\Runwire\Network\Internal\VerifiedOutboundTlsPeers::peerName($ready);
                $done = static function (?Connection $tunneled, ?Throwable $failureReason) use (
                    &$failure, &$completed, $loop,
                ): void {
                    ++$completed;
                    $failure = $failureReason;
                    if ($failureReason === null) {
                        expect($tunneled)->not->toBeNull();
                    }
                    $loop->stop();
                };
                try {
                    if ($protocol === 'http') {
                        (new HttpConnectTunnel(
                            $loop,
                            $ready,
                            new HttpConnectHandshake('user', 'secret', verifiedProxyTransport: true),
                            '127.0.0.1',
                            443,
                            $done,
                            verifiedProxyPeerName: $expectedProxy,
                        ))->start();
                    } else {
                        (new Socks5Tunnel(
                            $loop,
                            $ready,
                            new Socks5Handshake(username: 'user', password: 'secret', verifiedProxyTransport: true),
                            '127.0.0.1',
                            443,
                            $done,
                            verifiedProxyPeerName: $expectedProxy,
                        ))->start();
                    }
                } catch (Throwable $problem) {
                    $failure = $problem;
                    $loop->stop();
                }
            },
            new DialOptions(tls: new TlsClientOptions('localhost', caFile: $caFile, alpnProtocols: ['http/1.1'])),
        );
        $loop->run();
        expect($expired)->toBeFalse();

        return [
            'received' => $received,
            'verified' => $verified,
            'error' => $failure,
            'completed' => $completed,
            'accepted' => $accepted,
        ];
    } finally {
        $loop->cancel($watchdog);
        $client?->abort();
        $listener->abortConnections();
        $listener->close();
        $dialer->close();
        unlink($caFile);
        unlink($keyFile);
        rmdir($dir);
        expect($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    }
}

it('sends authenticated CONNECT only over an independently verified proxy TLS socket', function (): void {
    $result = runwireAuthenticatedProxyInterop('http');
    expect($result['error'])->toBeNull()
        ->and($result['accepted'])->toBeTrue()
        ->and($result['verified'])->toBe('localhost')
        ->and($result['completed'])->toBe(1)
        ->and($result['received'])->toContain('CONNECT 127.0.0.1:443 HTTP/1.1')
        ->and($result['received'])->toContain('Proxy-Authorization: Basic ' . base64_encode('user:secret'));
});

it('negotiates password-authenticated SOCKS5 over verified TLS with one terminal callback', function (): void {
    $result = runwireAuthenticatedProxyInterop('socks5');
    expect($result['error'])->toBeNull()
        ->and($result['accepted'])->toBeTrue()
        ->and($result['verified'])->toBe('localhost')
        ->and($result['completed'])->toBe(1)
        ->and($result['received'])->toBe("\x05\x01\x00\x01\x7f\x00\x00\x01\x01\xbb");
});

it('rejects authenticated CONNECT before writing credentials when configured proxy TLS peer mismatches', function (): void {
    $result = runwireAuthenticatedProxyInterop('http', 'wrong.example');
    expect($result['error'])->toBeInstanceOf(LogicException::class)
        ->and($result['verified'])->toBe('localhost')
        ->and($result['received'])->toBe('')
        ->and($result['completed'])->toBe(0);
});

it('rejects live SOCKS5 password authentication failure before submitting CONNECT', function (): void {
    $result = runwireAuthenticatedProxyInterop('socks5', 'localhost', true);
    expect($result['error'])->toBeInstanceOf(RuntimeException::class)
        ->and($result['verified'])->toBe('localhost')
        ->and($result['accepted'])->toBeTrue()
        ->and($result['completed'])->toBe(1)
        ->and($result['received'])->toBe('');
});
