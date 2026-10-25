<?php

declare(strict_types=1);

it('pins real impaired QUIC peer packets and a complete native stream recovery gate', function (): void {
    $root = dirname(__DIR__);
    $peer = file_get_contents($root . '/tests/interop/aioquic_quic_echo_server.py');
    $script = file_get_contents($root . '/tests/interop/quic_impaired_native_session.sh');
    $workflow = file_get_contents($root . '/.github/workflows/security-standards.yml');
    expect($peer)->toBeString()
        ->toContain('HandshakeCompleted', '--impair-post-handshake')
        ->toContain('received % 19 == 0', 'received % 11 == 0')
        ->toContain('self._dropped_datagrams += 1', 'self._delayed_datagrams += 1');
    expect($script)->toBeString()
        ->toContain('quic_session_echo_client.php')
        ->toContain('dropped_datagrams >= 1', 'delayed_datagrams >= 1')
        ->toContain('native_reset_storm_count == 6')
        ->toContain('verified_post_reset_bidi_streams == 2');
    expect($workflow)->toBeString()
        ->toContain('quic_impaired_native_session.sh')
        ->toContain('native-quic-impaired-php-');
    $lines = [];
    $exit = 0;
    exec('bash -n ' . escapeshellarg($root . '/tests/interop/quic_impaired_native_session.sh') . ' 2>&1', $lines, $exit);
    expect($exit)->toBe(0, implode("\n", $lines));
});
