<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http2OutboundClient;
use Infocyph\Runwire\Http\Client\HttpNegotiatingOutboundClient;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\TlsClientOptions;

it('interoperates over real verified TLS h2 with strict and explicitly negotiated client ALPN', function (string $mode): void {
    $auto = $mode !== 'strict-h2';
    $fallbackH1 = $mode === 'fallback-h1';
    $rejectH1 = $mode === 'reject-h1';
    if (!function_exists('proc_open') || !extension_loaded('openssl')) {
        throw new RuntimeException('Independent H2 QA requires process and OpenSSL tooling.');
    }
    $probe = [];
    $status = 0;
    exec('python3 -c ' . escapeshellarg('import h2.connection'), $probe, $status);
    expect($status)->toBe(0, 'Independent H2 QA must provide hyper-h2.');

    $dir = sys_get_temp_dir() . '/runwire-hyper-h2-' . bin2hex(random_bytes(6));
    expect(mkdir($dir, 0700))->toBeTrue();
    $cfg = $dir . '/openssl.cnf';
    $cert = $dir . '/cert.pem';
    $keyFile = $dir . '/key.pem';
    file_put_contents($cfg, "[req]\ndistinguished_name=req_dn\nreq_extensions=v3_req\n[req_dn]\n[v3_req]\nsubjectAltName=IP:127.0.0.1,DNS:localhost\n");

    $root = dirname(__DIR__, 2);
    $loop = null;
    $executor = null;
    $dialer = null;
    $race = null;
    $watchdog = null;
    $process = null;
    $pipes = [];

    try {
        $key = openssl_pkey_new(['private_key_bits' => 2_048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Independent H2 TLS fixture private key generation failed.');
        }

        $csr = openssl_csr_new(['commonName' => 'localhost'], $key, [
            'config' => $cfg, 'req_extensions' => 'v3_req', 'digest_alg' => 'sha256',
        ]);
        if (!$csr instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('Independent H2 TLS fixture CSR generation failed.');
        }

        /** @var OpenSSLAsymmetricKey $key */
        $signed = openssl_csr_sign($csr, null, $key, 1, [
            'config' => $cfg, 'x509_extensions' => 'v3_req', 'digest_alg' => 'sha256',
        ]);
        if (!$signed instanceof OpenSSLCertificate) {
            throw new RuntimeException('Independent H2 TLS fixture certificate signing failed.');
        }

        openssl_x509_export($signed, $certificate);
        /** @var OpenSSLAsymmetricKey $key */
        openssl_pkey_export($key, $private);
        file_put_contents($cert, $certificate);
        file_put_contents($keyFile, $private);

        $script = $root . '/tests/fixtures/h2_reference_peer.py';
        $peerCommand = ['python3', '-u', $script, $cert, $keyFile];
        if ($fallbackH1 || $rejectH1) {
            $peerCommand[] = $rejectH1 ? 'deny' : 'http1';
        }
        $process = proc_open(
            $peerCommand,
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

        $client = $auto ? HttpNegotiatingOutboundClient::class : Http2OutboundClient::class;
        $policy = new ClientProtocolPolicy(
            ProtocolVersion::HTTP_2,
            [ProtocolVersion::HTTP_2, ProtocolVersion::HTTP_1_1],
            allowFallback: $auto && !$rejectH1,
        );
        $client::connect(
            $loop,
            $race,
            new ClientRequest('GET', 'https://127.0.0.1:' . $port . '/independent', protocol: $policy),
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
            new DialOptions(tls: new TlsClientOptions(
                '127.0.0.1',
                caFile: $cert,
                alpnProtocols: $auto && !$rejectH1 ? ['h2', 'http/1.1'] : ['h2'],
            )),
        );
        $loop->run();
        expect($completed)->toBe(1);
        if ($rejectH1) {
            expect($failure)->toBeInstanceOf(Throwable::class)
                ->and($statusCode)->toBeNull()
                ->and($body)->toBe('');
        } else {
            expect($failure)->toBeNull()
                ->and($statusCode)->toBe(200)
                ->and($type)->toBe('text/plain')
                ->and($reference)->toBe($fallbackH1 ? 'hyper-h2-fallback' : 'hyper-h2')
                ->and($body)->toBe($fallbackH1 ? 'hyper-h2-h1-fallback' : 'hyper-h2-reference');
        }
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
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
})->with([
    'strict H2 only' => ['strict-h2'],
    'verified H2 with explicit H1 fallback permission' => ['auto-h2'],
    'verified H1 fallback when TLS peer cannot negotiate H2' => ['fallback-h1'],
    'strict H2 rejects H1-only peer without publishing request' => ['reject-h1'],
]);
