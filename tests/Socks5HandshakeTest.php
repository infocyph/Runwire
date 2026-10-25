<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\Proxy\Socks5Handshake;

it('supports IPv4 and IPv6 SOCKS5 CONNECT but never leaks disallowed DNS', function (): void {
    $local = new Socks5Handshake();
    expect(bin2hex($local->greeting()))->toBe('050100')
        ->and(bin2hex($local->connectRequest('127.0.0.1', 443)))->toBe('050100017f00000101bb')
        ->and(bin2hex($local->connectRequest('[::1]', 80)))->toBe('05010004' . str_repeat('00', 15) . '01' . '0050');
    $local->verifyMethod("\x05\x00");
    expect(fn() => $local->connectRequest('internal.example', 443))
        ->toThrow(InvalidArgumentException::class, 'pre-resolved IP');
    expect(fn() => $local->verifyMethod("\x05\x02"))->toThrow(RuntimeException::class);
});

it('sends remote DNS only by opt-in and handles fragmented CONNECT replies', function (): void {
    $proxy = new Socks5Handshake(remoteDns: true);
    $frame = $proxy->connectRequest('service.example', 8443);
    expect(bin2hex(substr($frame, 0, 5)))->toBe('050100030f')
        ->and(substr($frame, 5, 15))->toBe('service.example')
        ->and(bin2hex(substr($frame, -2)))->toBe('20fb');
    $reply = "\x05\x00\x00\x03\x04host\x1f\x90";
    expect($proxy->replyLength(substr($reply, 0, 3)))->toBeNull()
        ->and($proxy->replyLength(substr($reply, 0, 4)))->toBeNull()
        ->and($proxy->replyLength(substr($reply, 0, -1)))->toBeNull()
        ->and($proxy->replyLength($reply . 'application'))->toBe(strlen($reply));
});

it('rejects plaintext credential exchange and downgrade to anonymous SOCKS5', function (): void {
    expect(fn() => new Socks5Handshake(username: 'user', password: 'secret'))
        ->toThrow(InvalidArgumentException::class, 'verified encrypted');
    $proxy = new Socks5Handshake(username: 'user', password: 'secret', verifiedProxyTransport: true);
    expect(bin2hex($proxy->greeting()))->toBe('050102')
        ->and($proxy->credentials())->toBe("\x01\x04user\x06secret");
    $proxy->verifyMethod("\x05\x02");
    $proxy->verifyAuthentication("\x01\x00");
    foreach (["\x05\x00", "\x05\xff", "\x04\x02"] as $reply) {
        expect(fn() => $proxy->verifyMethod($reply))->toThrow(RuntimeException::class);
    }
    expect(fn() => $proxy->verifyAuthentication("\x01\x01"))->toThrow(RuntimeException::class);
});

it('rejects hostile SOCKS5 domains and malformed bounded replies', function (): void {
    $proxy = new Socks5Handshake(remoteDns: true);
    foreach (['', 'bad..name', '-bad.example', "evil\r\nhost", str_repeat('a', 64) . '.com'] as $host) {
        expect(fn() => $proxy->connectRequest($host, 443))->toThrow(InvalidArgumentException::class);
    }
    expect(fn() => $proxy->connectRequest('example.com', 0))->toThrow(InvalidArgumentException::class);
    foreach (["\x05\x01\x00\x01", "\x05\x00\x01\x01", "\x05\x00\x00\x05", "\x05\x00\x00\x03\x00\x00\x00"] as $reply) {
        expect(fn() => $proxy->replyLength($reply))->toThrow(RuntimeException::class);
    }
    expect(fn() => $proxy->replyLength(str_repeat('x', 65_537)))->toThrow(RuntimeException::class);
});
