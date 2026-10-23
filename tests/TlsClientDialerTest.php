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
function runwireClientTlsAttempt(array $identity, string $peer, ?string $caFile): array
{
    [, $certificate, $private] = $identity;
    $loop = new SelectLoop();
    $listener = TcpListener::bind(
        '127.0.0.1:0',
        new ListenerOptions(maxConnections: 4),
        new ConnectionLimits(),
        new TlsOptions($certificate, $private, handshakeTimeoutSeconds: 1.0),
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
    }, new DialOptions(tls: new TlsClientOptions($peer, caFile: $caFile)));
    $loop->run();
    $loop->cancel($timeout);
    $connection?->abort();
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
