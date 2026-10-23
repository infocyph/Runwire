<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Network\TlsClientOptions;
use Infocyph\Runwire\Network\TlsOptions;

/** @return array{string, string, string} */
function runwireClientTlsIdentity(): array
{
    $directory = sys_get_temp_dir() . '/runwire-client-tls-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create TLS identity fixture directory.');
    }
    $key = openssl_pkey_new(['private_key_bits' => 2_048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    expect($key)->not->toBeFalse()->and($csr)->not->toBeFalse()->and($cert)->not->toBeFalse();
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem);
    $certificate = $directory . '/cert.pem';
    $private = $directory . '/key.pem';
    file_put_contents($certificate, $certPem);
    file_put_contents($private, $keyPem);

    return [$directory, $certificate, $private];
}

/** @param array{string, string, string} $identity */
function runwireRemoveClientTlsIdentity(array $identity): void
{
    [$directory, $cert, $key] = $identity;
    unlink($cert);
    unlink($key);
    rmdir($directory);
}

/**
 * @param array{string, string, string} $identity
 * @return array{?Connection, ?Throwable, bool}
 */
function runwireClientTlsAttempt(
    array $identity,
    string $peer,
    ?string $caFile,
    ?int $serverCryptoMethod = null,
    ?array $serverAlpn = null,
): array
{
    [, $certificate, $private] = $identity;
    $loop = new SelectLoop();
    $listener = TcpListener::bind(
        '127.0.0.1:0',
        new ListenerOptions(maxConnections: 4),
        new ConnectionLimits(),
        new TlsOptions(
            $certificate,
            $private,
            alpnProtocols: $serverAlpn ?? ['h2', 'http/1.1'],
            handshakeTimeoutSeconds: 1.0,
            cryptoMethod: $serverCryptoMethod,
            extraContext: $serverExtraContext ?? [],
        ),
    );
    $accepted = false;
    $clientResolved = false;
    $listener->start($loop, static function (Connection $connection) use (&$accepted, &$clientResolved, $loop): void {
        $accepted = $connection->isEncrypted();
        if ($clientResolved) {
            $loop->stop();
        }
    });
    $dialer = new Dialer($loop);
    $connection = null;
    $failure = null;
    $timeout = $loop->delay(1.5, static function () use ($loop): void {
        $loop->stop();
    });
    $dialer->connect('tcp://' . $listener->address(), function (?Connection $ready, ?Throwable $error) use (
        &$connection,
        &$failure,
        &$clientResolved,
        &$accepted,
        $loop,
    ): void {
        $connection = $ready;
        $failure = $error;
        $clientResolved = true;
        if ($error !== null || $accepted) {
            $loop->stop();
        }
    }, new DialOptions(tls: $clientOptions ?? new TlsClientOptions($peer, caFile: $caFile)));
    $loop->run();
    $loop->cancel($timeout);
    $connection?->abort();
    $listener->abortConnections();
    $listener->close();
    $dialer->close();
    expect($dialer->pendingCount())->toBe(0)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);

    return [$connection, $failure, $accepted];
}

it('establishes a verified nonblocking TLS client connection with explicit trusted root', function (): void {
    $identity = runwireClientTlsIdentity();
    try {
        [$connection, $error, $accepted] = runwireClientTlsAttempt($identity, 'localhost', $identity[1]);
        expect($error)->toBeNull()
            ->and($connection)->toBeInstanceOf(Connection::class)
            ->and($connection->isEncrypted())->toBeTrue()
            ->and($accepted)->toBeTrue();
        $connection->abort();
    } finally {
        runwireRemoveClientTlsIdentity($identity);
    }
});

it('fails closed on wrong hostname without handing an unverified connection to callers', function (): void {
    $identity = runwireClientTlsIdentity();
    try {
        [$connection, $error] = runwireClientTlsAttempt($identity, 'wrong.invalid', $identity[1]);
        expect($connection)->toBeNull()
            ->and($error)->toBeInstanceOf(Throwable::class);
    } finally {
        runwireRemoveClientTlsIdentity($identity);
    }
});

it('fails closed on untrusted self-signed TLS server identity', function (): void {
    $identity = runwireClientTlsIdentity();
    try {
        [$connection, $error] = runwireClientTlsAttempt($identity, 'localhost', null);
        expect($connection)->toBeNull()
            ->and($error)->toBeInstanceOf(Throwable::class);
    } finally {
        runwireRemoveClientTlsIdentity($identity);
    }
});

it('rejects TLS policy on Unix sockets before opening any outgoing socket', function (): void {
    $loop = new SelectLoop();
    $dialer = new Dialer($loop);
    expect(fn() => $dialer->connect(
        'unix:///tmp/runwire-does-not-exist.sock',
        static function (): void {},
        new DialOptions(tls: new TlsClientOptions('localhost')),
    ))->toThrow(InvalidArgumentException::class)
        ->and($dialer->pendingCount())->toBe(0);
});


it('negotiates both TLS 1.2 and TLS 1.3 with explicit client verification and ALPN', function (): void {
    $identity = runwireClientTlsIdentity();
    try {
        foreach ([STREAM_CRYPTO_METHOD_TLSv1_2_SERVER, STREAM_CRYPTO_METHOD_TLSv1_3_SERVER] as $version) {
            [$connection, $error, $accepted] = runwireClientTlsAttempt(
                $identity,
                'localhost',
                $identity[1],
                $version,
                ['h2', 'http/1.1'],
            );
            expect($error)->toBeNull()
                ->and($connection)->toBeInstanceOf(Connection::class)
                ->and($connection->isEncrypted())->toBeTrue()
                ->and($connection->negotiatedProtocol())->toBe('h2')
                ->and($accepted)->toBeTrue();
            $connection->abort();
        }
    } finally {
        runwireRemoveClientTlsIdentity($identity);
    }
});

it('times out a silent TCP peer during client TLS negotiation and releases every watcher', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect($server)->toBeResource();
    $address = stream_socket_get_name($server, false);
    expect($address)->toBeString();
    $loop = new SelectLoop();
    $dialer = new Dialer($loop);
    $accepted = null;
    $serverWatcher = $loop->onReadable($server, function () use (&$accepted, $server, $loop, &$serverWatcher): void {
        $accepted = stream_socket_accept($server, 0.0);
        $loop->cancel($serverWatcher);
    });
    $failures = [];
    $dialer->connect('tcp://' . $address, function ($connection, $failure) use (&$failures, $loop): void {
        expect($connection)->toBeNull();
        $failures[] = $failure;
        $loop->stop();
    }, new DialOptions(
        connectTimeoutSeconds: 0.08,
        tls: new TlsClientOptions('localhost', handshakeTimeoutSeconds: 0.04),
    ));
    $loop->run();
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toBeInstanceOf(Throwable::class)
        ->and($dialer->pendingCount())->toBe(0);
    $dialer->close();
    if (is_resource($accepted)) {
        fclose($accepted);
    }
    fclose($server);
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});


it('accepts a trusted mutual TLS client identity using the server trust configuration', function (): void {
    $identity = runwireClientTlsIdentity();
    try {
        $serverContext = [
            'verify_peer' => true,
            'verify_peer_name' => false,
            'allow_self_signed' => false,
            'cafile' => $identity[1],
            'verify_depth' => 8,
        ];
        [$connection, $error, $accepted] = runwireClientTlsAttempt(
            $identity,
            'localhost',
            $identity[1],
            null,
            null,
            $serverContext,
            new TlsClientOptions(
                'localhost',
                caFile: $identity[1],
                clientCertificate: $identity[1],
                clientPrivateKey: $identity[2],
            ),
        );
        expect($error)->toBeNull()
            ->and($connection)->toBeInstanceOf(Connection::class)
            ->and($connection->isEncrypted())->toBeTrue()
            ->and($accepted)->toBeTrue();
        $connection->abort();
    } finally {
        runwireRemoveClientTlsIdentity($identity);
    }
});

it('fails a mutual TLS handshake when the server requires an absent client identity', function (): void {
    $identity = runwireClientTlsIdentity();
    try {
        [$connection, $error] = runwireClientTlsAttempt(
            $identity,
            'localhost',
            $identity[1],
            null,
            null,
            [
                'verify_peer' => true,
                'verify_peer_name' => false,
                'allow_self_signed' => false,
                'cafile' => $identity[1],
                'verify_depth' => 8,
            ],
        );
        expect($connection)->toBeNull()
            ->and($error)->toBeInstanceOf(Throwable::class);
    } finally {
        runwireRemoveClientTlsIdentity($identity);
    }
});
