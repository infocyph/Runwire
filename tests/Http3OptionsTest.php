<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\Http3Options;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Server;

it('requires bounded HTTP/3 poll and handshake timeouts', function (): void {
    expect(fn () => new Http3Options(pollTimeoutSeconds: 0.0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Http3Options(pollTimeoutSeconds: 1.01))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Http3Options(handshakeTimeoutSeconds: 0.0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Http3Options(handshakeTimeoutSeconds: 60.01))->toThrow(InvalidArgumentException::class);
});

it('keeps HTTP/3 0-RTT disabled for the 1.0 application contract', function (): void {
    expect(Http3Options::ZERO_RTT_ENABLED)->toBeFalse();
});

it('derives a narrow QUIC listener configuration from TLS material', function (): void {
    $certificate = tempnam(sys_get_temp_dir(), 'runwire-h3-cert-');
    $privateKey = tempnam(sys_get_temp_dir(), 'runwire-h3-key-');
    if ($certificate === false || $privateKey === false) {
        throw new RuntimeException('Unable to create temporary HTTP/3 TLS files.');
    }
    file_put_contents($certificate, 'certificate');
    file_put_contents($privateKey, 'private-key');

    try {
        $tls = new TlsOptions($certificate, $privateKey, 'secret');
        $options = (new Http3Options())->listenerOptions($tls);

        expect($options)->toBe([
            'local_cert' => realpath($certificate),
            'local_pk' => realpath($privateKey),
            'passphrase' => 'secret',
            'alpn' => 'h3',
            'reuse_port' => true,
        ]);
    } finally {
        if (file_exists($certificate)) {
            unlink($certificate);
        }
        if (file_exists($privateKey)) {
            unlink($privateKey);
        }
    }
});

it('requires TLS before HTTP/3 can be enabled on an HTTP server', function (): void {
    $server = Server::http('127.0.0.1:443', static function (): void {});
    expect(fn () => $server->withHttp3())->toThrow(InvalidArgumentException::class);
});
