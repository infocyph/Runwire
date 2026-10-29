<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\Proxy\HttpConnectHandshake;

it('builds well-formed target-authority HTTP CONNECT including bracketed IPv6', function (): void {
    $proxy = new HttpConnectHandshake();
    expect($proxy->request('example.com', 443))->toBe("CONNECT example.com:443 HTTP/1.1\r\nHost: example.com:443\r\n\r\n")
        ->and($proxy->request('::1', 8443))->toBe("CONNECT [::1]:8443 HTTP/1.1\r\nHost: [::1]:8443\r\n\r\n");
    expect(fn() => $proxy->request('http://evil', 443))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $proxy->request("bad\r\nHost:evil", 443))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $proxy->request('example.com', 0))->toThrow(InvalidArgumentException::class);
});

it('preserves tunneled bytes after valid fragmented CONNECT response header', function (): void {
    $handshake = new HttpConnectHandshake();
    $header = "HTTP/1.1 200 Connection Established\r\nX-Proxy: ok\r\n\r\n";
    expect($handshake->responseLength(substr($header, 0, -1)))->toBeNull()
        ->and($handshake->responseLength($header . "\x16\x03\x01"))->toBe(strlen($header));
});

it('rejects CONNECT authentication on unverified proxy transport', function (): void {
    expect(fn() => new HttpConnectHandshake('user', 'secret'))->toThrow(InvalidArgumentException::class, 'verified encrypted');
    $handshake = new HttpConnectHandshake('user', 'secret', true);
    expect($handshake->request('target.example', 443))->toContain(
        'Proxy-Authorization: Basic ' . base64_encode('user:secret') . "\r\n",
    );
    expect(fn() => new HttpConnectHandshake('bad:name', 'secret', true))->toThrow(InvalidArgumentException::class);
});

it('fails closed on CONNECT non-2xx, smuggling, oversized headers and body framing', function (): void {
    $handshake = new HttpConnectHandshake();
    foreach ([
        "HTTP/1.1 407 Proxy Authentication Required\r\n\r\n",
        "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n",
        "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
        "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n",
        "HTTP/1.1 200 OK\r\n Obsolete-Fold: yes\r\n\r\n",
        "HTTP/1.1 200 OK\r\nBroken-Header\r\n\r\n",
        "HTTP/1.1 200 OK\nHost: bad\n\n",
    ] as $reply) {
        if (str_contains($reply, "\r\n\r\n")) {
            expect(fn() => $handshake->responseLength($reply))->toThrow(RuntimeException::class);
        } else {
            expect($handshake->responseLength($reply))->toBeNull();
        }
    }
    expect(fn() => $handshake->responseLength(str_repeat('x', 8193)))->toThrow(RuntimeException::class);
});
