<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http2OutboundClient;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\TlsClientOptions;

it('interoperates over real verified TLS h2 with the independent upstream hyper-h2 server', function (): void {
    if (!function_exists('proc_open') || !extension_loaded('openssl')) {
        $this->markTestSkipped('Independent H2 reference requires process and OpenSSL test tooling.');
    }
    $probe = [];
    $status = 0;
    exec('python3 -c ' . escapeshellarg('import h2.connection'), $probe, $status);
    if ($status !== 0) {
        $this->markTestSkipped('Optional hyper-h2 Python reference is installed by the dedicated PR-only H2 QA workflow.');
    }

    $dir = sys_get_temp_dir() . '/runwire-hyper-h2-' . bin2hex(random_bytes(6));
    expect(mkdir($dir, 0700))->toBeTrue();
    $cfg = $dir . '/openssl.cnf';
    $cert = $dir . '/cert.pem';
    $keyFile = $dir . '/key.pem';
    file_put_contents($cfg, "[req]\ndistinguished_name=req_dn\nreq_extensions=v3_req\n[req_dn]\n[v3_req]\nsubjectAltName=IP:127.0.0.1,DNS:localhost\n");

    $loop = null;
    $executor = null;
    $dialer = null;
    $race = null;
    $watchdog = null;
    $process = null;
    $pipes = [];
    try {
        $key = openssl_pkey_new(['private_key_bits' => 2_048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key, [
            'config' => $cfg, 'req_extensions' => 'v3_req', 'digest_alg' => 'sha256',
        ]);
        $signed = openssl_csr_sign($csr, null, $key, 1, [
            'config' => $cfg, 'x509_extensions' => 'v3_req', 'digest_alg' => 'sha256',
        ]);
        expect($key)->not->toBeFalse()
            ->and($csr)->not->toBeFalse()
            ->and($signed)->not->toBeFalse();
        openssl_x509_export($signed, $certificate);
        openssl_pkey_export($key, $private);
        file_put_contents($cert, $certificate);
        file_put_contents($keyFile, $private);

        $script = dirname(__DIR__) . '/tests/fixtures/h2_reference_peer.py';
        $process = proc_open(
            ['python3', '-u', $script, $cert, $keyFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        expect(is_resource($process))->toBeTrue();
        stream_set_timeout($pipes[1], 5);
        $line = fgets($pipes[1]);
        expect($line)->not->toBeFalse();
        $port = (int) trim((string) $line);
        expect($port)->toBeGreaterThan(0)->toBeLessThanOrEqual(65_535);

        $loop = new SelectLoop();
        $executor = new ProcessExecutor($loop, 1, 2);
        $dialer = new Dialer($loop);
        $race = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
        $statusCode = null;
        $type = null;
        $reference = null;
        $body = '';
        $completed = 0;
        $failure = null;
        $watchdog = $loop->delay(5.0, static function () use ($loop): void {
            $loop->stop();
        });

        Http2OutboundClient::connect(
            $loop,
            $race,
            new ClientRequest('GET', 'https://127.0.0.1:' . $port . '/independent'),
            static function (int $code, Headers $headers) use (&$statusCode, &$type, &$reference): void {
                $statusCode = $code;
                $type = $headers->first('content-type');
                $reference = $headers->first('x-reference-server');
            },
            static function (string $chunk) use (&$body): void {
                $body .= $chunk;
            },
            static function (?Connection $unused, ?Throwable $error) use (&$completed, &$failure, $loop): void {
                expect($unused)->toBeNull();
                $failure = $error;
                ++$completed;
                $loop->stop();
            },
            new DialOptions(tls: new TlsClientOptions('127.0.0.1', caFile: $cert, alpnProtocols: ['h2'])),
        );
        $loop->run();
        expect($completed)->toBe(1)
            ->and($failure)->toBeNull()
            ->and($statusCode)->toBe(200)
            ->and($type)->toBe('text/plain')
            ->and($reference)->toBe('hyper-h2')
            ->and($body)->toBe('hyper-h2-reference');
    } finally {
        if ($loop instanceof SelectLoop && $watchdog !== null) {
            $loop->cancel($watchdog);
        }
        $race?->close();
        $dialer?->close();
        $executor?->close();
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
        foreach ([$keyFile, $cert, $cfg] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    }
    expect($loop?->diagnostics()->readWatchers)->toBe(0)
        ->and($loop?->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop?->diagnostics()->timersActive)->toBe(0);
});
