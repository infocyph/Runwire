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
use PHPUnit\Framework\Assert;

foreach ([
    'reversed independent responses' => 'default',
    'large flow-control windows and sibling fairness' => 'flow',
    'authenticated RST cancel with continuing sibling' => 'cancel',
    'slow H2 stream does not starve live sibling' => 'slow',
] as $scenario => $mode) {
    it('interoperates with upstream hyper-h2 over real TLS for parallel streams and flow credit: ' . $scenario, function () use ($mode): void {
        $flowPressure = $mode === 'flow';
        $cancelMode = $mode === 'cancel';
        if (!function_exists('proc_open') || !extension_loaded('openssl')) {
            throw new RuntimeException('Independent H2 multiplexer QA requires process and OpenSSL tooling.');
        }
        $probe = [];
        $status = 0;
        exec('python3 -c ' . escapeshellarg('import h2.connection'), $probe, $status);
        Assert::assertSame(0, $status, 'Independent upstream hyper-h2 is mandatory.');

        $dir = sys_get_temp_dir() . '/runwire-h2-multiplex-' . bin2hex(random_bytes(6));
        Assert::assertTrue(mkdir($dir, 0700));
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

            $csrPrivateKey = $key;
            $csr = openssl_csr_new(['commonName' => 'localhost'], $csrPrivateKey, [
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

            $peerCommand = [
                'python3',
                '-u',
                dirname(__DIR__, 2) . '/tests/fixtures/h2_multiplex_reference_peer.py',
                $cert,
                $keyFile,
            ];
            if ($mode !== 'default') {
                $peerCommand[] = $mode;
            }

            $process = proc_open(
                $peerCommand,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            Assert::assertTrue(is_resource($process));
            stream_set_timeout($pipes[1], 5);
            $portText = fgets($pipes[1]);
            Assert::assertIsString($portText);
            $port = (int) trim($portText);
            Assert::assertGreaterThan(0, $port);
            Assert::assertLessThanOrEqual(65_535, $port);

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
                    $loop,
                    $port,
                    &$owner,
                    &$ready,
                    &$headers,
                    &$bodies,
                    &$errors,
                    $cancelMode,
                ): void {
                    if ($connection === null || $failure !== null) {
                        $errors['dial'] = $failure ?? new RuntimeException('Missing TLS connection.');
                        $loop->stop();

                        return;
                    }
                    Assert::assertTrue($connection->isEncrypted());
                    Assert::assertSame('h2', $connection->negotiatedProtocol());
                    $owner = new Http2MultiplexConnection(
                        $loop,
                        $connection,
                        static function (Http2MultiplexConnection $transport) use (
                            &$ready,
                            &$headers,
                            &$bodies,
                            &$errors,
                            $loop,
                            $port,
                            $cancelMode,
                        ): void {
                            ++$ready;
                            $firstStreamId = 0;
                            foreach (['one', 'two'] as $path) {
                                $streamId = $transport->request(
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
                                if ($path === 'one') {
                                    $firstStreamId = $streamId;
                                }
                            }
                            if ($cancelMode) {
                                $transport->cancel($firstStreamId);
                            }
                        },
                        maxConcurrentStreams: 2,
                    );
                    $owner->start();
                },
                new DialOptions(tls: new TlsClientOptions('127.0.0.1', caFile: $cert, alpnProtocols: ['h2'])),
            );
            $loop->run();
            Assert::assertSame(1, $ready);
            Assert::assertCount(2, $errors);
            if (!array_key_exists('one', $errors) || !array_key_exists('two', $errors)) {
                throw new RuntimeException('Multiplex reference did not complete both requested streams.');
            };
            if ($cancelMode) {
                Assert::assertInstanceOf(RuntimeException::class, $errors['one']);
                Assert::assertNull($errors['two']);
                Assert::assertSame(['two' => [200, 'hyper-h2-multiplex']], $headers);
                Assert::assertSame(['two' => 'hyper-h2-second'], $bodies);
            } else {
                Assert::assertNull($errors['one']);
                Assert::assertNull($errors['two']);
                Assert::assertSame([
                    'two' => [200, 'hyper-h2-multiplex'],
                    'one' => [200, 'hyper-h2-multiplex'],
                ], $headers);
                Assert::assertSame($flowPressure
                    ? ['two' => str_repeat('3', 131_072), 'one' => str_repeat('1', 131_072)]
                    : ['two' => ($mode === 'slow' ? 'slow-second' : 'hyper-h2-second'), 'one' => 'hyper-h2-first'], $bodies);
            }
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
                fclose($pipe);
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
        Assert::assertSame(0, $loop->diagnostics()->readWatchers);
        Assert::assertSame(0, $loop->diagnostics()->writeWatchers);
        Assert::assertSame(0, $loop->diagnostics()->timersActive);
    });
}
