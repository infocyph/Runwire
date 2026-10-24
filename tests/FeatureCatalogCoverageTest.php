<?php

declare(strict_types=1);

it('documents every planned public capability with released and candidate host extension status', function (): void {
    $rst = file_get_contents(dirname(__DIR__) . '/docs/features.rst');
    expect($rst)->toBeString()
        ->and($rst)->toContain('Runwire 2.1.2', '3.0.0');

    $matched = preg_match_all(
        '/^   \* - ([^\n]+)\n((?:     - [^\n]+\n){4})/m',
        $rst,
        $rows,
        PREG_SET_ORDER,
    );
    expect($matched)->toBeGreaterThanOrEqual(30);

    $labels = [];
    foreach ($rows as $row) {
        $label = trim($row[1]);
        expect($label)->not->toBe('');
        $labels[] = $label;
        preg_match_all('/^     - ([^\n]+)$/m', $row[2], $cells);
        expect($cells[1])->toHaveCount(4);
        foreach ($cells[1] as $status) {
            expect(trim($status))->not->toBe('');
        }
    }
    expect(count(array_unique($labels)))->toBe(count($labels));

    $required = [
        'TCP and Unix server',
        'TLS server',
        'HTTP/1.1 server',
        'HTTP/2 server',
        'HTTP/3 server',
        'HTTP/1.1 outbound',
        'HTTP/2 outbound',
        'HTTP/3 outbound',
        'WebSocket server',
        'WebSocket client',
        'H2/H3 extended CONNECT',
        'WebTransport',
        'Inbound and outbound UDP',
        'Native QUIC outbound',
        'TCP/TLS/Unix dialer',
        'Async DNS/Happy Eyeballs',
        'Pooled connections',
        'CONNECT/SOCKS5 proxy',
        'Bidirectional relay',
        'Fiber tasks and cancellation',
        'Async disk jobs',
        'File/network transfer',
        'Atomic file durability',
        'Native process supervision',
        'Automatic autoscaling',
        'FPM/FrankenPHP/RoadRunner',
        'Swoole/OpenSwoole',
        'Optional ext-event',
        'Security and diagnostics',
    ];
    foreach ($required as $capability) {
        expect($labels)->toContain($capability);
    }
});
