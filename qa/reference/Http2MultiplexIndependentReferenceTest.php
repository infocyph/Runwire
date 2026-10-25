<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http2MultiplexConnection;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\TlsClientOptions;

it('interoperates with upstream hyper-h2 for two concurrent, reversed-order streams on real verified TLS', function (): void {
    if (!function_exists('proc_open') || !extension_loaded('openssl')) {
        throw new RuntimeException('Independent H2 multiplexer QA requires process and OpenSSL tooling.');
    }
    $probe = [];
    $status = 0;
    exec('python3 -c ' . escapeshellarg('import h2.connection'), $probe, $status);
    expect($status)->toBe(0, 'Independent upstream hyper-h2 is mandatory.');

    $dir = sys_get_temp_dir() . '/runwire-h2-multiplex-' . bin2hex(random_bytes(6));
    expect(mkdir($dir, 0700))->toBeTrue();
    $config = $dir . '/openssl.cnf';
    $cert = $dir . '/cert.pem';
    $keyFile = $dir . '/key.pem';
    file_put_contents($config, "[req]\ndistinguished_name=req_dn\nreq_extensions=v3_req\n[req_dn]\n[v3_req]\nsubjectAltName=IP:127.0.0.1,DNS:localhost\n");
    $loop = null;
    $dialer = null;
    $owner = null;
    $watchdog = null;
    $process = null;
    $pipes = [];

    try {
        $key = openssl_pkey_new(['private_key_bits' => 2_048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Independent H2 multiplexer fixture key generation failed.');
        }

        $csr = openssl_csr_new(['commonName' => 'localhost'], $key, [
            'config' => $config, 'req_extensions' => 'v3_req', 'digest_alg' => 'sha256',
        ]);
        if (!$csr instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('Independent H2 multiplexer fixture CSR generation failed.');
        }

        $signed = openssl_csr_sign($csr, null, $key, 1, [
            'config' => $config, 'x509_extensions' => 'v3_req', 'digest_alg' => 'sha256',
        ]);
        if (!$signed instanceof OpenSSLCertificate) {
            throw new RuntimeException('Independent H2 multiplexer certificate signing failed.');
        }
        openssl_x509_export($signed, $certificate);
        openssl_pkey_export($key, $private);
        file_put_contents($cert, $certificate);
        file_put_contents($keyFile, $private);

        $process = proc_open(
            ['python3', '-u', dirname(__DIR__, 2) . '/tests/fixtures/h2_multiplex_reference_peer.py', $cert, $keyFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        expect(is_resource($process))->toBeTrue();
        stream_set_timeout($pipes[1], 5);
        $portText = fgets($pipes[1]);
        expect($portText)->not->toBeFalse();
        $port = (int) trim((string) $portText);
        expect($port)->toBeGreaterThan(0)->toBeLessThanOrEqual(65_535);

        $loop = new SelectLoop();
        $dialer = new Dialer($loop);
        $headers = [];
        $bodies = [];
        $errors = [];
        $ready = 0;
        $watchdog = $loop->delay(7.0, static function () use ($loop): void {
            $loop->stop();
        });
        $dialer->connect(
            'tcp://127.0.0.1:' . $port,
            static function (?Connection $connection, ?Throwable $failure) use (
                $loop, $port, &$owner, &$ready, &$headers, &$bodies, &$errors,
            ): void {
                if ($connection === null || $failure !== null) {
                    $errors['dial'] = $failure ?? new RuntimeException('Missing TLS connection.');
                    $loop->stop();

                    return;
                }
                expect($connection->isEncrypted())->toBeTrue()
                    ->and($connection->negotiatedProtocol())->toBe('h2');
                $owner = new Http2MultiplexConnection(
                    $loop,
                    $connection,
                    static function (Http2MultiplexConnection $transport) use (
                        &$ready, &$headers, &$bodies, &$errors, $loop, $port,
                    ): void {
                        ++$ready;
                        foreach (['one', 'two'] as $path) {
                            $transport->request(
                                new ClientRequest('GET', 'https://127.0.0.1:' . $port . '/multiplex/' . $path),
                                static function (int $status, Headers $received) use (&$headers, $path): void {
                                    $headers[$path] = [$status, $received->first('x-reference-server')];
                                },
                                static function (string $chunk) use (&$bodies, $path): void {
                                    $bodies[$path] = ($bodies[$path] ?? '') . $chunk;
                                },
                                static function (?Throwable $error) use (&$errors, $path, $loop): void {
                                    $errors[$path] = $error;
                                    if (array_key_exists('one', $errors) && array_key_exists('two', $errors)) {
                                        $loop->stop();
                                    }
                                },
                            );
                        }
                    },
                    maxConcurrentStreams: 2,
                );
                $owner->start();
            },
            new DialOptions(tls: new TlsClientOptions('127.0.0.1', caFile: $cert, alpnProtocols: ['h2'])),
        );
        $loop->run();
        expect($ready)->toBe(1)
            ->and($errors)->toHaveCount(2)
            ->and($errors['one'])->toBeNull()
            ->and($errors['two'])->toBeNull()
            ->and($headers)->toBe([
                'two' => [200, 'hyper-h2-multiplex'],
                'one' => [200, 'hyper-h2-multiplex'],
            ])
            ->and($bodies)->toBe(['two' => 'hyper-h2-second', 'one' => 'hyper-h2-first']);
    } finally {
        $owner?->close();
        $dialer?->close();
        if ($loop instanceof SelectLoop && $watchdog !== null) {
            $loop->cancel($watchdog);
        }
        if (is_resource($process)) {
            $state = proc_get_status($process);
            if ($state['running']) {
                proc_terminate($process);
            }
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($process)) {
            proc_close($process);
        }
        foreach ([$keyFile, $cert, $config] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
