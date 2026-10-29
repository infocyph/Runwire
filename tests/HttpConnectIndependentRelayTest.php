<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\Proxy\HttpConnectHandshake;
use Infocyph\Runwire\Network\Proxy\HttpConnectTunnel;
use Infocyph\Runwire\Network\TlsClientOptions;

/**
 * Independent Python TLS proxy forwards real bytes through a separately bound TCP origin.
 *
 * @return array{status:int, relayed:bool, authenticated:bool, callbacks:int, error:?Throwable, response:string, verified:?string}
 */
function runwireHttpConnectIndependentRelay(bool $deny): array
{
    $dir = sys_get_temp_dir() . '/runwire-connect-relay-' . bin2hex(random_bytes(7));
    if (!mkdir($dir, 0700)) {
        throw new RuntimeException('Cannot prepare independent CONNECT relay TLS reference.');
    }
    $key = openssl_pkey_new(['private_key_bits' => 2_048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    expect($key)->not->toBeFalse()->and($csr)->not->toBeFalse()->and($certificate)->not->toBeFalse();
    openssl_x509_export($certificate, $pem);
    openssl_pkey_export($key, $privatePem);
    $ca = $dir . '/ca.pem';
    $private = $dir . '/key.pem';
    file_put_contents($ca, $pem);
    file_put_contents($private, $privatePem);

    $process = proc_open(
        ['python3', __DIR__ . '/fixtures/independent_http_connect_relay.py', $ca, $private, $deny ? 'deny' : 'success'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        unlink($ca);
        unlink($private);
        rmdir($dir);
        throw new RuntimeException('Cannot launch independent TLS CONNECT relay.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $loop = new SelectLoop();
    $dialer = new Dialer($loop);
    $connection = null;
    $error = null;
    $response = '';
    $verified = null;
    $callbacks = 0;
    $timeout = null;
    try {
        $ready = '';
        $start = microtime(true);
        while (!str_contains($ready, "\n") && microtime(true) - $start < 4) {
            $ready .= stream_get_contents($pipes[1]);
            if (str_contains($ready, "\n") || !proc_get_status($process)['running']) {
                break;
            }
            usleep(10_000);
        }
        if (preg_match('/^READY ([0-9]+) ([0-9]+)\n/', $ready, $matches) !== 1) {
            throw new RuntimeException('Independent CONNECT peer startup failed: ' . stream_get_contents($pipes[2]));
        }
        $proxyPort = (int) $matches[1];
        $originPort = (int) $matches[2];
        $expired = false;
        $timeout = $loop->delay(5, static function () use (&$expired, $loop): void {
            $expired = true;
            $loop->stop();
        });
        $dialer->connect(
            'tcp://127.0.0.1:' . $proxyPort,
            static function (?Connection $ready, ?Throwable $failure) use (
                &$connection, &$error, &$response, &$verified, &$callbacks, $loop, $originPort, $deny,
            ): void {
                if ($failure !== null || $ready === null) {
                    $error = $failure ?? new RuntimeException('Independent TLS proxy dial failed.');
                    $loop->stop();

                    return;
                }
                $connection = $ready;
                $verified = \Infocyph\Runwire\Network\Internal\VerifiedOutboundTlsPeers::peerName($ready);
                $handshake = new HttpConnectHandshake('user', $deny ? 'invalid' : 'secret', verifiedProxyTransport: true);
                (new HttpConnectTunnel(
                    $loop,
                    $ready,
                    $handshake,
                    '127.0.0.1',
                    $originPort,
                    static function (?Connection $tunneled, ?Throwable $proxyError) use (
                        &$callbacks, &$error, &$response, $loop,
                    ): void {
                        ++$callbacks;
                        if ($proxyError !== null || $tunneled === null) {
                            $error = $proxyError ?? new RuntimeException('CONNECT failed without error.');
                            $loop->stop();

                            return;
                        }
                        $tunneled->onData(static function (Connection $live) use (&$response, $loop): void {
                            $response .= $live->read();
                            $loop->stop();
                        });
                        if (!$tunneled->write('ping-from-runwire')->accepted()) {
                            $error = new RuntimeException('Tunneled payload exceeded the write budget.');
                            $loop->stop();
                        }
                    },
                    verifiedProxyPeerName: 'localhost',
                ))->start();
            },
            new DialOptions(tls: new TlsClientOptions('localhost', caFile: $ca, alpnProtocols: ['http/1.1'])),
        );
        $loop->run();
        expect($expired)->toBeFalse();
        $connection?->abort();

        $output = '';
        $status = null;
        $start = microtime(true);
        while (microtime(true) - $start < 3) {
            $output .= stream_get_contents($pipes[1]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(10_000);
        }
        $output .= stream_get_contents($pipes[1]);
        expect($status['running'] ?? true)->toBeFalse();
        $lines = array_values(array_filter(explode("\n", trim($output))));
        $peer = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        expect($peer)->toBeArray();

        return [
            'status' => $peer['status'],
            'relayed' => $peer['relayed'],
            'authenticated' => $peer['authenticated'],
            'callbacks' => $callbacks,
            'error' => $error,
            'response' => $response,
            'verified' => $verified,
        ];
    } finally {
        if ($timeout !== null) {
            $loop->cancel($timeout);
        }
        $connection?->abort();
        $dialer->close();
        proc_terminate($process);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($ca);
        unlink($private);
        rmdir($dir);
        expect($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    }
}

it('relays application bytes to a real target through independently authenticated TLS CONNECT proxy', function (): void {
    $result = runwireHttpConnectIndependentRelay(false);
    expect($result['status'])->toBe(200)
        ->and($result['authenticated'])->toBeTrue()
        ->and($result['relayed'])->toBeTrue()
        ->and($result['verified'])->toBe('localhost')
        ->and($result['callbacks'])->toBe(1)
        ->and($result['error'])->toBeNull()
        ->and($result['response'])->toBe('pong-from-origin');
});

it('refuses incorrect CONNECT credentials without contacting the independent origin', function (): void {
    $result = runwireHttpConnectIndependentRelay(true);
    expect($result['status'])->toBe(407)
        ->and($result['relayed'])->toBeFalse()
        ->and($result['authenticated'])->toBeFalse()
        ->and($result['verified'])->toBe('localhost')
        ->and($result['callbacks'])->toBe(1)
        ->and($result['error'])->toBeInstanceOf(RuntimeException::class)
        ->and($result['response'])->toBe('');
});
