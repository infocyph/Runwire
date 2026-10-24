<?php

declare(strict_types=1);

use Infocyph\Runwire\DatagramServer;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Protocol\LineCodec;
use Infocyph\Runwire\StreamServer;

it('creates a fresh codec for each stream connection', function (): void {
    $server = StreamServer::tcp(
        '127.0.0.1:9000',
        static fn () => new LineCodec(),
        static function (): void {},
    );

    expect($server->codec())->toBeInstanceOf(LineCodec::class)
        ->and($server->codec())->not->toBe($server->codec());
});

it('rejects TLS configuration for Unix stream servers', function (): void {
    $certificate = tempnam(sys_get_temp_dir(), 'runwire-cert-');
    if ($certificate === false) {
        throw new RuntimeException('Unable to create temporary certificate path.');
    }
    file_put_contents($certificate, 'not-a-real-certificate');

    try {
        $tls = new TlsOptions($certificate);
        $server = StreamServer::unix('/tmp/runwire.sock', static fn () => new LineCodec(), static function (): void {});
        expect(fn () => $server->withTls($tls))->toThrow(LogicException::class);
    } finally {
        if (file_exists($certificate)) {
            unlink($certificate);
        }
    }
});

it('supports automatic datagram worker sizing and rejects negative counts', function (): void {
    $server = new DatagramServer('udp', '127.0.0.1:9001', static function (): void {}, workers: 0);

    expect($server->workers)->toBe(0)
        ->and(fn () => new DatagramServer('udp', '127.0.0.1:9001', static function (): void {}, workers: -1))
        ->toThrow(InvalidArgumentException::class);
});
