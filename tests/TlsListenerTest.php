<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Network\TlsOptions;

function runwireTlsFiles(): array
{
    $directory = sys_get_temp_dir() . '/runwire-tls-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700, true);
    $key = openssl_pkey_new(['private_key_bits' => 2_048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    expect($key)->not->toBeFalse()->and($csr)->not->toBeFalse()->and($certificate)->not->toBeFalse();
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($key, $keyPem);
    $certificateFile = $directory . '/cert.pem';
    $keyFile = $directory . '/key.pem';
    file_put_contents($certificateFile, $certificatePem);
    file_put_contents($keyFile, $keyPem);
    return [$directory, $certificateFile, $keyFile];
}

function cleanupRunwireTlsFiles(string $directory, string $certificateFile, string $keyFile): void
{
    if (file_exists($certificateFile)) {
        unlink($certificateFile);
    }
    if (file_exists($keyFile)) {
        unlink($keyFile);
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
}

it('bounds stalled TLS handshakes', function (): void {
    [$directory, $certificateFile, $keyFile] = runwireTlsFiles();
    try {
        $loop = new SelectLoop();
        $listener = TcpListener::bind(
            '127.0.0.1:0',
            new ListenerOptions(maxConnections: 2),
            new ConnectionLimits(),
            new TlsOptions($certificateFile, $keyFile, handshakeTimeoutSeconds: 0.04),
        );
        $listener->start($loop, static function (Connection $connection): void {
            $connection->abort();
            throw new RuntimeException('A stalled plain client must not become a TLS connection.');
        });
        $client = stream_socket_client('tcp://' . $listener->address(), $errno, $error, 1.0);
        expect($client)->toBeResource();
        $loop->delay(0.09, static fn () => $loop->stop());
        $loop->run();
        expect($listener->pendingHandshakes())->toBe(0)->and($listener->rejectedConnections())->toBe(1);
        fclose($client);
        $listener->close();
    } finally {
        cleanupRunwireTlsFiles($directory, $certificateFile, $keyFile);
    }
})->skip(!extension_loaded('openssl'), 'OpenSSL extension is required.');

it('negotiates native TLS and ALPN without blocking the server loop', function (): void {
    [$directory, $certificateFile, $keyFile] = runwireTlsFiles();
    try {
        $loop = new SelectLoop();
        $listener = TcpListener::bind(
            '127.0.0.1:0',
            new ListenerOptions(maxConnections: 8),
            new ConnectionLimits(idleTimeoutSeconds: 2.0),
            new TlsOptions($certificateFile, $keyFile, alpnProtocols: ['h2', 'http/1.1'], handshakeTimeoutSeconds: 1.0),
        );
        $serverProtocol = null;
        $encrypted = false;
        $listener->start($loop, static function (Connection $connection) use (&$serverProtocol, &$encrypted, $loop): void {
            $serverProtocol = $connection->negotiatedProtocol();
            $encrypted = $connection->isEncrypted();
            $connection->onData(static function (Connection $connection): void {
                if ($connection->read() === 'ping') {
                    $connection->write('pong');
                    $connection->closeGracefully();
                }
            });
            $connection->onClose(static fn () => $loop->stop());
        });

        $pid = pcntl_fork();
        expect($pid)->toBeGreaterThanOrEqual(0);
        if ($pid === 0) {
            $context = stream_context_create(['ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'alpn_protocols' => 'h2,http/1.1',
            ]]);
            $client = stream_socket_client('tcp://' . $listener->address(), $errno, $error, 1.0, STREAM_CLIENT_CONNECT, $context);
            if (!is_resource($client) || stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                // phpcs:ignore PHPForge.PHP.ForbiddenFunctions.Found -- Forked TLS client must not execute parent test flow.
                exit(20);
            }
            $meta = stream_get_meta_data($client);
            $protocol = is_array($meta['crypto'] ?? null) ? ($meta['crypto']['alpn_protocol'] ?? null) : null;
            fwrite($client, 'ping');
            stream_set_timeout($client, 2);
            $reply = stream_get_contents($client);
            fclose($client);
            // phpcs:ignore PHPForge.PHP.ForbiddenFunctions.Found -- Forked TLS client must not execute parent test flow.
            exit($reply === 'pong' && $protocol === 'h2' ? 0 : 21);
        }

        $loop->delay(2.0, static fn () => $loop->stop());
        $loop->run();
        pcntl_waitpid($pid, $status);
        expect(pcntl_wifexited($status))->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and($encrypted)->toBeTrue()
            ->and($serverProtocol)->toBe('h2');
        $listener->close();
    } finally {
        cleanupRunwireTlsFiles($directory, $certificateFile, $keyFile);
    }
})->skip(!extension_loaded('openssl'), 'OpenSSL extension is required.');
