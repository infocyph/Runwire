<?php

declare(strict_types=1);

it('audits complete source behavior evidence over bounded stdin without opening caller-selected paths', function (): void {
    $ledgerJson = file_get_contents(dirname(__DIR__) . '/qa/release-baseline/c4-migration-behavior-ledger.json');
    if (!is_string($ledgerJson)) {
        throw new RuntimeException('Unable to load the C4 migration ledger fixture.');
    }
    $ledger = json_decode(
        $ledgerJson,
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    if (!is_array($ledger) || !is_string($ledger['publishedRevision'] ?? null)
        || !is_array($ledger['entries'] ?? null)) {
        throw new RuntimeException('C4 migration ledger fixture has an invalid envelope.');
    }
    $entries = [];
    foreach ($ledger['entries'] as $entry) {
        if (!is_array($entry) || !is_string($entry['symbol'] ?? null)) {
            throw new RuntimeException('C4 migration ledger fixture contains an invalid symbol.');
        }
        $entries[] = $entry;
    }
    $diff = [
        'schemaVersion' => 1,
        'baselineRevision' => $ledger['publishedRevision'],
        'candidateRevision' => str_repeat('b', 40),
        'changed' => array_map(
            static fn(array $entry): array => [
                'type' => 'source_changed',
                'symbol' => $entry['symbol'],
            ],
            $entries,
        ),
    ];
    $packet = tempnam(sys_get_temp_dir(), 'runwire-c4-stdin-');
    if (!is_string($packet)) {
        throw new RuntimeException('Unable to allocate C4 audit input fixture.');
    }
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
        if (!is_array($result)) {
            throw new RuntimeException('C4 audit returned a non-object JSON payload.');
        }
        expect($result['allChangedSymbolsDispositioned'])->toBeTrue()
            ->and($result['testBackedDispositionCount'])->toBe(count($entries));

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
