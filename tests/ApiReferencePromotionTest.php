<?php

declare(strict_types=1);

it('captures inline, multiline, nullable and promoted public PHP fields without private leakage', function (): void {
    $fixture = dirname(__DIR__) . '/tests/fixtures/api_reference_promotions.py';
    expect(is_file($fixture))->toBeTrue();
    $output = [];
    $exit = 0;
    exec('python3 ' . escapeshellarg($fixture) . ' 2>&1', $output, $exit);
    expect($exit)->toBe(0, implode("\n", $output));
});
