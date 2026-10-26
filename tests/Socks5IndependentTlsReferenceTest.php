<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\Proxy\Socks5Handshake;
use Infocyph\Runwire\Network\Proxy\Socks5Tunnel;
use Infocyph\Runwire\Network\TlsClientOptions;

/**
 * Run a separate Python TLS peer implementing RFC 1928 and RFC 1929 itself.
 *
 * @return array{error:?Throwable,completed:int,verified:?string,peer:array<string,mixed>}
 */
function runwireIndependentSocksReference(string $host, bool $remoteDns, string $behavior = 'success'): array
{
    $dir = sys_get_temp_dir() . '/runwire-socks-ref-' . bin2hex(random_bytes(7));
    if (!mkdir($dir, 0700)) {
        throw new RuntimeException('Cannot create independent SOCKS5 TLS test directory.');
    }

    $key = openssl_pkey_new(['private_key_bits' => 2_048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    expect($key)->not->toBeFalse()->and($csr)->not->toBeFalse()->and($cert)->not->toBeFalse();
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem);
    $ca = $dir . '/ca.pem';
    $privateKey = $dir . '/private.pem';
    file_put_contents($ca, $certPem);
    file_put_contents($privateKey, $keyPem);

    $command = ['python3', __DIR__ . '/fixtures/independent_socks5_tls_server.py', $ca, $privateKey, $host, $behavior];
    $process = proc_open($command, [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        unlink($ca);
        unlink($privateKey);
        rmdir($dir);
        throw new RuntimeException('Cannot launch independent Python SOCKS5 TLS reference peer.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $loop = new SelectLoop();
    $dialer = new Dialer($loop);
    $connection = null;
    $error = null;
    $completed = 0;
    $verified = null;
    $expired = false;
    $watchdog = null;
    try {
        $readyLine = '';
        $started = microtime(true);
        while (!str_contains($readyLine, "\n") && microtime(true) - $started < 4.0) {
            $readyLine .= stream_get_contents($pipes[1]);
            if (str_contains($readyLine, "\n")) {
                break;
            }
            $state = proc_get_status($process);
            if (!$state['running']) {
                break;
            }
            usleep(10_000);
        }
        if (preg_match('/^READY ([0-9]+)\n/', $readyLine, $matches) !== 1) {
            throw new RuntimeException('Independent Python SOCKS5 peer could not bind: ' . stream_get_contents($pipes[2]));
        }
        $port = (int) $matches[1];
        $watchdog = $loop->delay(5, static function () use (&$expired, $loop): void {
            $expired = true;
            $loop->stop();
        });

        $dialer->connect(
            'tcp://127.0.0.1:' . $port,
            static function (?Connection $ready, ?Throwable $dialError) use (
                &$connection, &$error, &$completed, &$verified,
                $loop, $host, $remoteDns,
            ): void {
                if ($dialError !== null || $ready === null) {
                    $error = $dialError ?? new RuntimeException('Verified TLS dial failed.');
                    $loop->stop();

                    return;
                }
                $connection = $ready;
                $verified = \Infocyph\Runwire\Network\Internal\VerifiedOutboundTlsPeers::peerName($ready);
                (new Socks5Tunnel(
                    $loop,
                    $ready,
                    new Socks5Handshake(
                        remoteDns: $remoteDns,
                        username: 'user',
                        password: 'secret',
                        verifiedProxyTransport: true,
                    ),
                    $host,
                    443,
                    static function (?Connection $tunneled, ?Throwable $proxyError) use (
                        &$completed, &$error, $loop,
                    ): void {
                        ++$completed;
                        $error = $proxyError;
                        if ($proxyError === null) {
                            expect($tunneled)->not->toBeNull();
                        }
                        $loop->stop();
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
        $state = null;
        $finished = microtime(true);
        while (microtime(true) - $finished < 2.0) {
            $output .= stream_get_contents($pipes[1]);
            $state = proc_get_status($process);
            if (!$state['running']) {
                break;
            }
            usleep(10_000);
        }
        $output .= stream_get_contents($pipes[1]);
        expect($state['running'] ?? true)->toBeFalse();
        $lines = array_filter(explode("\n", trim($output)));
        $decoded = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        expect($decoded)->toBeArray();

        return ['error' => $error, 'completed' => $completed, 'verified' => $verified, 'peer' => $decoded];
    } finally {
        if ($watchdog !== null) {
            $loop->cancel($watchdog);
        }
        $connection?->abort();
        $dialer->close();
        proc_terminate($process);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($ca);
        unlink($privateKey);
        rmdir($dir);
        expect($loop->diagnostics()->readWatchers)->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    }
}

it('authenticates RFC1929 against an independent Python TLS SOCKS5 proxy with explicit remote DNS', function (): void {
    $proof = runwireIndependentSocksReference('origin.example', true);
    expect($proof['error'])->toBeNull()
        ->and($proof['completed'])->toBe(1)
        ->and($proof['verified'])->toBe('localhost')
        ->and($proof['peer']['authenticated'])->toBeTrue()
        ->and($proof['peer']['atyp'])->toBe(3)
        ->and($proof['peer']['target'])->toBe('origin.example')
        ->and($proof['peer']['port'])->toBe(443);
});

it('authenticates an independent SOCKS5 TLS peer without disclosing a hostname in local DNS mode', function (): void {
    $proof = runwireIndependentSocksReference('127.0.0.1', false);
    expect($proof['error'])->toBeNull()
        ->and($proof['completed'])->toBe(1)
        ->and($proof['verified'])->toBe('localhost')
        ->and($proof['peer']['authenticated'])->toBeTrue()
        ->and($proof['peer']['atyp'])->toBe(1)
        ->and($proof['peer']['target'])->toBe('127.0.0.1');
});

it('refuses an anonymous-method downgrade from the independent verified TLS reference peer', function (): void {
    $proof = runwireIndependentSocksReference('127.0.0.1', false, 'downgrade');
    expect($proof['error'])->toBeInstanceOf(RuntimeException::class)
        ->and($proof['completed'])->toBe(1)
        ->and($proof['verified'])->toBe('localhost')
        ->and($proof['peer']['mode'])->toBe('downgrade')
        ->and($proof['peer']['extra_bytes'])->toBe(0);
});
