<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Network\TlsClientOptions;
use Infocyph\Runwire\Network\TlsOptions;

$directory = sys_get_temp_dir() . '/runwire-isolated-tls-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create test fixture directory.');
}
$certificate = $directory . '/cert.pem';
$privateKey = $directory . '/key.pem';
$configFile = $directory . '/openssl.cnf';
$success = false;
$message = '';
$listener = null;
$dialer = null;
$client = null;
$serverConnection = null;

try {
    file_put_contents($configFile, "[req]\ndistinguished_name=req_dn\nreq_extensions=v3_req\n[req_dn]\n[v3_req]\nsubjectAltName=IP:127.0.0.1\n");
    $key = openssl_pkey_new(['private_key_bits' => 2_048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(
        ['commonName' => 'localhost'],
        $key,
        ['config' => $configFile, 'req_extensions' => 'v3_req', 'digest_alg' => 'sha256'],
    );
    $cert = openssl_csr_sign(
        $csr,
        null,
        $key,
        1,
        ['config' => $configFile, 'x509_extensions' => 'v3_req', 'digest_alg' => 'sha256'],
    );
    if (!$key || !$csr || !$cert || !openssl_x509_export($cert, $certPem) || !openssl_pkey_export($key, $keyPem)) {
        throw new RuntimeException('Could not generate independent TLS test certificate.');
    }
    file_put_contents($certificate, $certPem);
    file_put_contents($privateKey, $keyPem);

    // Deliberately poison the process-global SSL context in this disposable
    // subprocess; individual outgoing dials must never inherit that identity.
    stream_context_set_default(['ssl' => ['local_cert' => $directory . '/missing-identity.pem']]);

    $loop = new SelectLoop();
    $listener = TcpListener::bind(
        '127.0.0.1:0',
        tls: new TlsOptions($certificate, $privateKey, alpnProtocols: ['http/1.1']),
    );
    $ready = false;
    $serverEncrypted = false;
    $listener->start($loop, static function (Connection $connection) use (&$serverConnection, &$serverEncrypted, &$ready, $loop): void {
        $serverConnection = $connection;
        $serverEncrypted = $connection->isEncrypted();
        if ($ready) {
            $loop->stop();
        }
    });
    $dialer = new Dialer($loop);
    $failure = null;
    $watchdog = $loop->delay(4.0, static function () use ($loop): void {
        $loop->stop();
    });
    $dialer->connect(
        'tcp://' . $listener->address(),
        static function (?Connection $connection, ?Throwable $error) use (&$client, &$ready, &$failure, &$serverEncrypted, $loop): void {
            $client = $connection;
            $failure = $error;
            $ready = true;
            if ($serverEncrypted || $error !== null) {
                $loop->stop();
            }
        },
        new DialOptions(
            connectTimeoutSeconds: 3.0,
            tls: new TlsClientOptions('127.0.0.1', caFile: $certificate, alpnProtocols: ['http/1.1']),
        ),
    );
    $loop->run();
    $loop->cancel($watchdog);
    $success = $ready && $serverEncrypted && $failure === null && $client instanceof Connection && $client->isEncrypted();
    if (!$success) {
        $message = 'TLS connection isolation failed: ' . ($failure?->getMessage() ?? 'no completed peer');
    }
} catch (Throwable $error) {
    $message = $error->getMessage();
} finally {
    $client?->abort();
    $serverConnection?->abort();
    $listener?->abortConnections();
    $listener?->close();
    $dialer?->close();
    foreach ([$certificate, $privateKey, $configFile] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}
if (!$success) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
echo "isolated_tls_ok\n";
