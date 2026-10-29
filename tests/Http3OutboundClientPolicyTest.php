<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http3OutboundClient;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\QuicClientOptions;

it('refuses H3 origin substitution and unapproved ALPN before allocating native QUIC', function (): void {
    $loop = new SelectLoop();
    $request = new ClientRequest('GET', 'https://example.com/');
    $h3 = new ClientRequest('GET', 'https://example.com/', protocol: new ClientProtocolPolicy(
        preferred: ProtocolVersion::HTTP_3,
        allowedVersions: [ProtocolVersion::HTTP_3],
    ));
    $headers = static function (): void {};
    $body = static function (): void {};
    $done = static function (): void {};

    expect(fn() => Http3OutboundClient::connect($loop, $h3, new QuicClientOptions('wrong.example'), $headers, $body, $done))
        ->toThrow(InvalidArgumentException::class, 'original origin')
        ->and(fn() => Http3OutboundClient::connect($loop, $h3,
            new QuicClientOptions('example.com', alpnProtocols: ['h3', 'h2']), $headers, $body, $done))
        ->toThrow(InvalidArgumentException::class, 'h3-only')
        ->and(fn() => Http3OutboundClient::connect($loop, $request,
            new QuicClientOptions('example.com'), $headers, $body, $done))
        ->toThrow(\Infocyph\Runwire\Exception\RuntimeUnavailableException::class);
    expect($loop->diagnostics()->timersActive)->toBe(0);
});
