<?php

declare(strict_types=1);

it('does not inherit a poisoned PHP default TLS context when dialing a verified peer', function (): void {
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/fixtures/dialer_isolated_tls.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
    );
    expect($process)->toBeResource();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    expect($status)->toBe(0, is_string($stderr) ? $stderr : '')
        ->and($stdout)->toContain('isolated_tls_ok');
});
