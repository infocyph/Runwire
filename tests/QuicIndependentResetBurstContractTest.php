<?php

declare(strict_types=1);

it('requires native QUIC burst resets on six sibling streams before independent uni FIN', function (): void {
    $script = file_get_contents(dirname(__DIR__) . '/tests/fixtures/quic_session_echo_client.php');
    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/security-standards.yml');
    expect($script)->toBeString()
        ->toContain('$nativeResets = 0;')
        ->toContain('$index < 6', '++$nativeResets')
        ->toContain('if ($nativeResets === 6)')
        ->toContain('native_reset_storm_count', '$nativeResets')
        ->toContain('verified_post_reset_bidi_streams', '$postResetBidi')
        ->toContain("'unidirectional_streams_delivered' => 1");
    expect($workflow)->toBeString()
        ->toContain('native_reset_storm_count == 6')
        ->toContain('native_reset_then_peer_uni_fin == true')
        ->toContain('runwire-native-quic-session-php-');
});

it('lints the independent native QUIC fixture as valid PHP before optional extension CI', function (): void {
    $path = dirname(__DIR__) . '/tests/fixtures/quic_session_echo_client.php';
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    expect($status)->toBe(0, implode("\n", $output));
});
