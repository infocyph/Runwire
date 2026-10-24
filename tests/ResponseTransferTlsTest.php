<?php

declare(strict_types=1);

use Closure;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http1\Http1Connection;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseTransfer;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Runtime\CoroutineRequestHandler;

function responseTransferTlsFiles(): array
{
    $directory = sys_get_temp_dir() . '/runwire-transfer-tls-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700, true);
    $key = openssl_pkey_new(['private_key_bits' => 2_048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    if ($key === false || $csr === false || $certificate === false) {
        throw new RuntimeException('Unable to create response-transfer TLS fixture.');
    }

    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($key, $keyPem);
    $certificateFile = $directory . '/cert.pem';
    $keyFile = $directory . '/key.pem';
    file_put_contents($certificateFile, $certificatePem);
    file_put_contents($keyFile, $keyPem);

    return [$directory, $certificateFile, $keyFile];
}

function cleanupResponseTransferTlsFiles(string $directory, string $certificateFile, string $keyFile): void
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

it('streams through real TLS HTTP1 backpressure to a slow reader', function (): void {
    if (!extension_loaded('openssl') || !function_exists('pcntl_fork')) {
        expect(extension_loaded('openssl') && function_exists('pcntl_fork'))->toBeFalse();

        return;
    }

    [$directory, $certificateFile, $keyFile] = responseTransferTlsFiles();
    $payload = str_repeat('bounded-transfer-tls-', 32_768);
    $expectedHash = hash('sha256', $payload);

    try {
        $loop = new SelectLoop();
        $runtime = new CoroutineRuntime($loop);
        $handler = new CoroutineRequestHandler(
            $runtime,
            static function (
                HttpRequest $request,
                ResponseWriterInterface $writer,
                CoroutineScope $scope,
            ) use ($payload): void {
                expect($request->target)->toBe('/transfer');

                $source = tmpfile();
                if (!is_resource($source)) {
                    throw new RuntimeException('Unable to create response-transfer source fixture.');
                }
                fwrite($source, $payload);
                rewind($source);

                $writer->start(200, new Headers([
                    new HeaderField('content-length', (string) strlen($payload)),
                    new HeaderField('connection', 'close'),
                ]));
                ResponseTransfer::stream(
                    $scope,
                    $source,
                    $writer,
                    chunkBytes: 4_096,
                    chunksPerTurn: 2,
                );
            },
        );
        $handler->attachLoop($loop);

        $listener = TcpListener::bind(
            '127.0.0.1:0',
            new ListenerOptions(maxConnections: 4),
            new ConnectionLimits(
                sendLowWatermarkBytes: 4_096,
                sendHighWatermarkBytes: 8_192,
                maxSendBufferBytes: 65_536,
                maxWriteBytesPerTick: 4_096,
            ),
            new TlsOptions(
                $certificateFile,
                $keyFile,
                alpnProtocols: ['http/1.1'],
                handshakeTimeoutSeconds: 1.0,
            ),
        );
        $listener->start(
            $loop,
            static function (Connection $connection) use ($loop, $handler): void {
                new Http1Connection(
                    $loop,
                    $connection,
                    new Http1Limits(maxResponseChunkBytes: 4_096),
                    $handler,
                );
                $connection->onClose(static function () use ($loop): void {
                    $loop->stop();
                });
            },
        );

        $pid = pcntl_fork();
        expect($pid)->toBeGreaterThanOrEqual(0);
        if ($pid === 0) {
            $context = stream_context_create(['ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'alpn_protocols' => 'http/1.1',
            ]]);
            $client = stream_socket_client(
                'tcp://' . $listener->address(),
                $errno,
                $error,
                1.0,
                STREAM_CLIENT_CONNECT,
                $context,
            );
            if (
                !is_resource($client)
                || stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true
            ) {
                (Closure::fromCallable('exit'))(20);
            }

            fwrite($client, "GET /transfer HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
            stream_set_timeout($client, 3);
            $status = fgets($client);
            $length = null;
            while (($line = fgets($client)) !== false && $line !== "\r\n") {
                if (str_starts_with(strtolower($line), 'content-length:')) {
                    $length = (int) trim(substr($line, strlen('content-length:')));
                }
            }

            $hash = hash_init('sha256');
            $received = 0;
            while (!feof($client)) {
                $chunk = fread($client, 1_024);
                if ($chunk === false) {
                    (Closure::fromCallable('exit'))(21);
                }
                if ($chunk === '') {
                    continue;
                }
                hash_update($hash, $chunk);
                $received += strlen($chunk);
                usleep(200);
            }
            fclose($client);

            $valid = is_string($status)
                && str_starts_with($status, 'HTTP/1.1 200')
                && $length === $received
                && $length === strlen($payload)
                && hash_final($hash) === $expectedHash;
            (Closure::fromCallable('exit'))($valid ? 0 : 22);
        }

        $loop->delay(5.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        pcntl_waitpid($pid, $status);

        expect(pcntl_wifexited($status))->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0);

        $listener->close();
    } finally {
        cleanupResponseTransferTlsFiles($directory, $certificateFile, $keyFile);
    }
});
