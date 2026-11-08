<?php

declare(strict_types=1);

it('audits complete source behavior evidence over bounded stdin without opening caller-selected paths', function (): void {
    $ledger = json_decode(
        file_get_contents(dirname(__DIR__) . '/qa/release-baseline/c4-migration-behavior-ledger.json'),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    $diff = [
        'schemaVersion' => 1,
        'baselineRevision' => $ledger['publishedRevision'],
        'candidateRevision' => str_repeat('b', 40),
        'changed' => array_map(
            static fn(array $entry): array => [
                'type' => 'source_changed',
                'symbol' => $entry['symbol'],
            ],
            $ledger['entries'],
        ),
    ];
    $packet = tempnam(sys_get_temp_dir(), 'runwire-c4-stdin-');
    expect($packet)->toBeString();
    try {
        file_put_contents($packet, json_encode(['diff' => $diff, 'ledger' => $ledger], JSON_THROW_ON_ERROR));
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(dirname(__DIR__) . '/tools/c4-migration-behavior-audit.php')
            . ' < ' . escapeshellarg($packet);
        $lines = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $lines, $exitCode);
        expect($exitCode)->toBe(0, implode("\n", $lines));
        $result = json_decode(implode("\n", $lines), true, 32, JSON_THROW_ON_ERROR);
        expect($result['allChangedSymbolsDispositioned'])->toBeTrue()
            ->and($result['testBackedDispositionCount'])->toBe(count($ledger['entries']));

        file_put_contents($packet, '{"diff":{"schemaVersion":1},"ledger":{}}');
        $lines = [];
        exec($command . ' 2>&1', $lines, $exitCode);
        expect($exitCode)->not->toBe(0);
    } finally {
        if (is_file($packet)) {
            unlink($packet);
        }
    }
});
