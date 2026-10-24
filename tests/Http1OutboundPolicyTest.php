<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http1OutboundClient;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\TlsClientOptions;

it('refuses unsafe outbound H1 TLS and ALPN identity substitutions before allocating sockets', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 1);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $h1 = new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1]);
    $plaintext = new ClientProtocolPolicy(ProtocolVersion::HTTP_1_1, [ProtocolVersion::HTTP_1_1], allowPlaintext: true);
    $onHeaders = static function (): void {};
    $onBody = static function (): void {};
    $done = static function (): void {};

    try {
        expect(fn() => Http1OutboundClient::connect(
            $loop, $dialer, new ClientRequest('GET', 'https://example.test/', protocol: $h1),
            $onHeaders, $onBody, $done,
            new DialOptions(tls: new TlsClientOptions('different.test', alpnProtocols: ['http/1.1'])),
        ))->toThrow(InvalidArgumentException::class, 'exact requested origin');

        expect(fn() => Http1OutboundClient::connect(
            $loop, $dialer, new ClientRequest('GET', 'https://example.test/', protocol: $h1),
            $onHeaders, $onBody, $done,
            new DialOptions(tls: new TlsClientOptions('example.test', alpnProtocols: ['h2'])),
        ))->toThrow(InvalidArgumentException::class, 'HTTP/1.1 ALPN');

        expect(fn() => Http1OutboundClient::connect(
            $loop, $dialer, new ClientRequest('GET', 'http://example.test/', protocol: $plaintext),
            $onHeaders, $onBody, $done,
            new DialOptions(tls: new TlsClientOptions('example.test', alpnProtocols: ['http/1.1'])),
        ))->toThrow(InvalidArgumentException::class, 'cannot carry a TLS policy');

        expect($dialer->pendingCount())->toBe(0)
            ->and($numeric->pendingCount())->toBe(0)
            ->and($loop->diagnostics()->writeWatchers)->toBe(0)
            ->and($loop->diagnostics()->timersActive)->toBe(0);
    } finally {
        $dialer->close();
        $numeric->close();
        $executor->close();
    }
});
