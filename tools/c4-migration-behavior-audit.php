<?php

declare(strict_types=1);

/**
 * Fail closed if any changed published implementation lacks a reviewed
 * migration disposition and an executable, source-present regression test.
 * This is a documentation coverage gate, not proof that all historical
 * runtime behavior is backward compatible.
 */
if ($argc !== 3) {
    throw new InvalidArgumentException(
        'Usage: php tools/c4-migration-behavior-audit.php <source-diff.json> <ledger.json>',
    );
}

$diff = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$ledger = json_decode((string) file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($diff) || !is_array($ledger) || ($diff['schemaVersion'] ?? null) !== 1
    || ($ledger['schemaVersion'] ?? null) !== 1
    || ($ledger['publishedRevision'] ?? null) !== $diff['baselineRevision']
    || !is_array($diff['changed'] ?? null)
    || !is_array($ledger['entries'] ?? null)
    || count($ledger['entries']) < 30) {
    throw new RuntimeException('Missing complete 2.1.2-to-3.0 migration ledger inputs.');
}

$changed = [];
foreach ($diff['changed'] as $record) {
    if (($record['type'] ?? null) !== 'source_changed') {
        continue;
    }
    $symbol = $record['symbol'] ?? null;
    if (!is_string($symbol) || $symbol === '' || isset($changed[$symbol])) {
        throw new RuntimeException('Invalid duplicate or missing changed public symbol.');
    }
    $changed[$symbol] = true;
}

$reviewed = [];
foreach ($ledger['entries'] as $entry) {
    if (!is_array($entry)) {
        throw new RuntimeException('Migration ledger contains a non-object entry.');
    }
    $symbol = $entry['symbol'] ?? null;
    $path = $entry['executableTest'] ?? null;
    $behavior = $entry['behavior'] ?? null;
    $migration = $entry['migration'] ?? null;
    if (!is_string($symbol) || !str_starts_with($symbol, 'Infocyph\\Runwire\\')
        || isset($reviewed[$symbol]) || !isset($changed[$symbol])
        || !is_string($path) || preg_match('#^tests/[A-Za-z0-9/_-]+Test\\.php$#D', $path) !== 1
        || !is_file(dirname(__DIR__) . '/' . $path)
        || !is_string($behavior) || strlen($behavior) < 20
        || !is_string($migration) || strlen($migration) < 35
        || ($entry['disposition'] ?? null) !== 'documented_test_backed_contract'
        || ($entry['requiresReleaseSignoff'] ?? null) !== true) {
        throw new RuntimeException('Unreviewed, untested or incorrectly classified migration: ' . (string) $symbol);
    }
    $reviewed[$symbol] = true;
}

$missing = array_diff_key($changed, $reviewed);
if ($missing !== [] || count($reviewed) !== count($changed)) {
    throw new RuntimeException('Changed public implementations are missing dispositions: '
        . implode(', ', array_keys($missing)));
}

echo json_encode([
    'publishedRevision' => $diff['baselineRevision'],
    'candidateRevision' => $diff['candidateRevision'],
    'changedPublicImplementationSymbols' => count($changed),
    'testBackedDispositionCount' => count($reviewed),
    'allChangedSymbolsDispositioned' => true,
    'behavioralCompatibilityCertified' => false,
    'maintainerReleaseSignoffClaimed' => false,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
