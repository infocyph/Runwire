<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\HttpNegotiatingOutboundClient;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\TlsClientOptions;

it('rejects unsupported H3 and unsafe H1/H2 fallback before any socket or DNS work', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $callback = static function (): void {};
    $h3 = new ClientProtocolPolicy(
        ProtocolVersion::HTTP_3,
        [ProtocolVersion::HTTP_3, ProtocolVersion::HTTP_2],
        allowFallback: true,
    );
    $h2Plain = new ClientProtocolPolicy(
        ProtocolVersion::HTTP_2,
        [ProtocolVersion::HTTP_2, ProtocolVersion::HTTP_1_1],
        allowFallback: true,
        allowPlaintext: true,
    );
    try {
        expect(fn() => HttpNegotiatingOutboundClient::connect(
            $loop,
            $dialer,
            new ClientRequest('GET', 'https://localhost/remote', protocol: $h3),
            $callback,
            $callback,
            $callback,
        ))->toThrow(RuntimeUnavailableException::class, 'QUIC');
        expect(fn() => HttpNegotiatingOutboundClient::connect(
            $loop,
            $dialer,
            new ClientRequest('GET', 'http://localhost/remote', protocol: $h2Plain),
            $callback,
            $callback,
            $callback,
        ))->toThrow(RuntimeUnavailableException::class, 'Plaintext');
        expect(fn() => HttpNegotiatingOutboundClient::connect(
            $loop,
            $dialer,
            new ClientRequest('GET', 'ftp://localhost/remote'),
            $callback,
            $callback,
            $callback,
        ))->toThrow(InvalidArgumentException::class, 'absolute http(s) origin');
        expect($numeric->pendingCount())->toBe(0)
            ->and($dialer->pendingCount())->toBe(0)
            ->and($executor->diagnostics()['submitted'])->toBe(0);
    } finally {
        $dialer->close();
        $numeric->close();
        $executor->close();
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});

it('refuses wrong peer identity and unexpected TLS ALPN offers before launching a dial', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $callback = static function (): void {};
    $request = new ClientRequest(
        'GET',
        'https://localhost/auto',
        protocol: new ClientProtocolPolicy(
            ProtocolVersion::HTTP_2,
            [ProtocolVersion::HTTP_2, ProtocolVersion::HTTP_1_1],
            allowFallback: true,
        ),
    );
    try {
        foreach ([
            new TlsClientOptions('other.invalid', alpnProtocols: ['h2', 'http/1.1']),
            new TlsClientOptions('localhost', alpnProtocols: ['http/1.1', 'h2']),
            new TlsClientOptions('localhost', alpnProtocols: ['h2']),
        ] as $tls) {
            expect(fn() => HttpNegotiatingOutboundClient::connect(
                $loop,
                $dialer,
                $request,
                $callback,
                $callback,
                $callback,
                new DialOptions(tls: $tls),
            ))->toThrow(InvalidArgumentException::class, 'original peer');
        }
        expect($numeric->pendingCount())->toBe(0)
            ->and($dialer->pendingCount())->toBe(0)
            ->and($executor->diagnostics()['submitted'])->toBe(0);
    } finally {
        $dialer->close();
        $numeric->close();
        $executor->close();
    }
    expect($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
});
