<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
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

it('fails closed before any H1 outbound dial when an ALPN or fallback policy excludes H1', function (): void {
    $loop = new SelectLoop();
    $executor = new ProcessExecutor($loop, 1, 2);
    $numeric = new Dialer($loop);
    $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
    $requests = [
        new ClientRequest('GET', 'https://localhost/', protocol: new ClientProtocolPolicy(
            ProtocolVersion::HTTP_2,
            [ProtocolVersion::HTTP_2, ProtocolVersion::HTTP_1_1],
        )),
        new ClientRequest('GET', 'https://localhost/', protocol: new ClientProtocolPolicy(
            ProtocolVersion::HTTP_3,
            [ProtocolVersion::HTTP_3],
        )),
        new ClientRequest('GET', 'HTTPS://localhost/', protocol: new ClientProtocolPolicy(
            ProtocolVersion::HTTP_3,
            [ProtocolVersion::HTTP_3, ProtocolVersion::HTTP_1_1],
        )),
    ];
    try {
        foreach ($requests as $request) {
            expect(fn() => Http1OutboundClient::connect(
                $loop,
                $dialer,
                $request,
                static function (): void {},
                static function (): void {},
                static function (): void {},
            ))->toThrow(RuntimeUnavailableException::class)
                ->and($dialer->pendingCount())->toBe(0)
                ->and($numeric->pendingCount())->toBe(0)
                ->and($executor->diagnostics()['submitted'])->toBe(0);
        }
        $plaintextPolicy = new ClientProtocolPolicy(
            ProtocolVersion::HTTP_1_1,
            [ProtocolVersion::HTTP_1_1],
            allowPlaintext: true,
        );
        $http = new ClientRequest('GET', 'http://localhost/', protocol: $plaintextPolicy);
        expect(fn() => Http1OutboundClient::connect(
            $loop,
            $dialer,
            $http,
            static function (): void {},
            static function (): void {},
            static function (): void {},
            new DialOptions(tls: new TlsClientOptions('localhost', alpnProtocols: ['http/1.1'])),
        ))->toThrow(InvalidArgumentException::class, 'Plaintext HTTP');
        $https = new ClientRequest('GET', 'HTTPS://localhost/', protocol: new ClientProtocolPolicy(
            ProtocolVersion::HTTP_1_1,
            [ProtocolVersion::HTTP_1_1],
        ));
        expect(fn() => Http1OutboundClient::connect(
            $loop,
            $dialer,
            $https,
            static function (): void {},
            static function (): void {},
            static function (): void {},
            new DialOptions(tls: new TlsClientOptions('other.invalid', alpnProtocols: ['http/1.1'])),
        ))->toThrow(InvalidArgumentException::class, 'exact requested origin');
        expect(fn() => Http1OutboundClient::connect(
            $loop,
            $dialer,
            $https,
            static function (): void {},
            static function (): void {},
            static function (): void {},
            new DialOptions(tls: new TlsClientOptions('localhost', alpnProtocols: ['h2', 'http/1.1'])),
        ))->toThrow(InvalidArgumentException::class, 'HTTP/1.1 ALPN');
        expect($dialer->pendingCount())->toBe(0)
            ->and($numeric->pendingCount())->toBe(0)
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

it('never reclassifies verified TLS as plaintext under 48 explicit H1/H2/H3 permutations', function (): void {
    $versions = [ProtocolVersion::HTTP_1_1, ProtocolVersion::HTTP_2, ProtocolVersion::HTTP_3];
    $accepted = 0;
    $rejected = 0;
    foreach ($versions as $preferred) {
        foreach ([false, true] as $allowFallback) {
            foreach ([false, true] as $allowPlaintext) {
                foreach (['http/1.1', 'h2', 'h3', 'unexpected'] as $alpn) {
                    $policy = new ClientProtocolPolicy($preferred, $versions, $allowFallback, $allowPlaintext);
                    try {
                        $result = $policy->negotiate($alpn, true, $alpn === 'h3');
                        expect($result)->toBe(match ($alpn) {
                            'http/1.1' => ProtocolVersion::HTTP_1_1,
                            'h2' => ProtocolVersion::HTTP_2,
                            'h3' => ProtocolVersion::HTTP_3,
                        });
                        expect($allowFallback || $preferred === $result)->toBeTrue();
                        ++$accepted;
                    } catch (RuntimeUnavailableException) {
                        ++$rejected;
                    }
                }
            }
        }
    }
    expect($accepted + $rejected)->toBe(48)
        ->and($accepted)->toBeGreaterThan(0)
        ->and($rejected)->toBeGreaterThan(0);
    $strict = new ClientProtocolPolicy(
        ProtocolVersion::HTTP_1_1,
        [ProtocolVersion::HTTP_1_1],
        allowPlaintext: true,
    );
    expect(fn() => $strict->negotiate('http/1.1', false))->toThrow(RuntimeUnavailableException::class)
        ->and(fn() => $strict->negotiate(null, false, true))->toThrow(RuntimeUnavailableException::class);
});
