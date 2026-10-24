<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Network\TlsClientOptions;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\WebSocket\Internal\WebSocketWireCodec;
use Infocyph\Runwire\WebSocket\WebSocketClient;
use Infocyph\Runwire\WebSocket\WebSocketSession;

it('connects WSS with a trusted certificate and independently scripted 101 plus close frames', function (): void {
    $directory = sys_get_temp_dir() . '/runwire-wss-' . bin2hex(random_bytes(6));
    expect(mkdir($directory, 0700))->toBeTrue();
    $certFile = $directory . '/cert.pem';
    $keyFile = $directory . '/key.pem';
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    expect($key)->not->toBeFalse()->and($csr)->not->toBeFalse()->and($cert)->not->toBeFalse();
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem);
    file_put_contents($certFile, $certPem);
    file_put_contents($keyFile, $keyPem);

    $loop = new SelectLoop();
    $listener = null;
    $executor = new ProcessExecutor($loop, 1, 4);
    $dialer = new Dialer($loop);
    $race = new HappyEyeballsDialer($loop, $dialer, new AsyncDnsResolver($executor));
    $sessions = [];
    $peers = [];
    $received = [];
    $closed = [];
    $errors = [];
    $serverEncrypted = false;
    $watchdog = $loop->delay(3.0, static function () use ($loop): void {
        $loop->stop();
    });

    try {
        $listener = TcpListener::bind(
            '127.0.0.1:0',
            new ListenerOptions(maxConnections: 4),
            new ConnectionLimits(),
            new TlsOptions($certFile, $keyFile, alpnProtocols: ['http/1.1']),
        );
        $listener->start($loop, static function (Connection $peer) use (&$peers, &$serverEncrypted): void {
            $peers[] = $peer;
            $serverEncrypted = $peer->isEncrypted();
            $request = '';
            $peer->onData(static function (Connection $connection) use (&$request): void {
                $request .= $connection->read();
                if (!str_contains($request, "\r\n\r\n")) {
                    return;
                }
                preg_match('/^Sec-WebSocket-Key: (.+)$/mi', $request, $matches);
                $nonce = trim($matches[1] ?? '');
                $accept = base64_encode(sha1($nonce . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                $connection->write("HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                    . 'Sec-WebSocket-Accept: ' . $accept . "\r\nSec-WebSocket-Protocol: chat\r\n\r\n"
                    . WebSocketWireCodec::frame(0x1, 'tls-message')
                    . WebSocketWireCodec::frame(0x8, pack('n', 1000) . 'done'));
                $request = '';
            });
        });
        $port = (int) substr(strrchr($listener->address(), ':'), 1);
        WebSocketClient::connect(
            $loop,
            $race,
            'wss://localhost:' . $port . '/chat',
            static function (?WebSocketSession $session, ?Throwable $error) use (&$sessions, &$received, &$closed, &$errors, $loop): void {
                $errors[] = $error;
                if ($session === null) {
                    $loop->stop();

                    return;
                }
                $sessions[] = $session;
                $session->onMessage(static function (WebSocketSession $active, $message) use (&$received): void {
                    expect($active)->toBeInstanceOf(WebSocketSession::class);
                    $received[] = $message->data;
                });
                $session->onClose(static function (WebSocketSession $active, int $code, string $reason) use (&$closed, $loop): void {
                    expect($active->isClosed())->toBeTrue();
                    $closed[] = [$code, $reason];
                    $loop->stop();
                });
            },
            ['chat'],
            new DialOptions(connectTimeoutSeconds: 1.0, tls: new TlsClientOptions('localhost', caFile: $certFile, alpnProtocols: ['http/1.1'])),
        );
        $loop->run();

        expect($serverEncrypted)->toBeTrue()
            ->and($errors)->toBe([null])
            ->and($received)->toBe(['tls-message'])
            ->and($closed)->toBe([[1000, 'done']])
            ->and($sessions)->toHaveCount(1)
            ->and($sessions[0]->subprotocol())->toBe('chat');
    } finally {
        foreach ($sessions as $session) {
            $session->drain();
        }
        foreach ($peers as $peer) {
            $peer->abort();
        }
        $listener?->abortConnections();
        $listener?->close();
        $race->close();
        $dialer->close();
        $executor->close();
        $loop->cancel($watchdog);
        unlink($certFile);
        unlink($keyFile);
        rmdir($directory);
    }
});
