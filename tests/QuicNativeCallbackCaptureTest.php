<?php

declare(strict_types=1);

it('propagates the event loop and native reset counters through every async QUIC callback boundary', function (): void {
    $fixture = file_get_contents(dirname(__DIR__) . '/tests/fixtures/quic_session_echo_client.php');
    expect($fixture)->toBeString()
        ->toContain('use ($session, $loop, $stop, $exchange, $sendUni, $rejectUnsupported, $options, &$completed, &$nativeResets, &$postResetBidi, &$readyWrites): void')
        ->toContain('use ($session, $loop, $stop, $sendUni, $exchange, &$readyWrites, &$nativeResets, &$postResetBidi): void')
        ->toContain('use ($session, $loop, $stop, $sendUni, $exchange, &$readyWrites, &$nativeResets, &$postResetBidi): void')
        ->toContain('$loop->delay(0.20,')
        ->toContain('$postResetBidi === 2')
        ->toContain('$nativeResets < 24')
        ->toContain('Independent QUIC session interoperability timed out');

    $output = [];
    $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg(dirname(__DIR__) . '/tests/fixtures/quic_session_echo_client.php') . ' 2>&1', $output, $exit);
    expect($exit)->toBe(0, implode("\n", $output));
});
