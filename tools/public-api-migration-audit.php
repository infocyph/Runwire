<?php

declare(strict_types=1);

/**
 * Certify source-only public API delta against an explicitly reviewed policy.
 *
 * Usage: php tools/public-api-migration-audit.php <diff.json> <policy.json>
 *
 * The source parser cannot flatten traits and cannot prove behavioral BC.
 * Unexpected removed declarations or signature changes fail closed.
 */
if ($argc !== 3) {
    throw new InvalidArgumentException('Usage: php tools/public-api-migration-audit.php <diff.json> <policy.json>');
}

/** @return array<string,mixed> */
$read = static function (string $file): array {
    if (!is_file($file) || !is_readable($file) || filesize($file) > 2_097_152) {
        throw new InvalidArgumentException('Missing or oversized public API audit input.');
    }
    $value = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_is_list($value)) {
        throw new InvalidArgumentException('Invalid public API audit document.');
    }

    return $value;
};

$diff = $read($argv[1]);
$policy = $read($argv[2]);
if (($diff['schemaVersion'] ?? null) !== 1
    || ($policy['schemaVersion'] ?? null) !== 1
    || ($diff['propertyInventoryCompared'] ?? null) !== true
    || !is_string($diff['baselineRevision'] ?? null)
    || !hash_equals($policy['publishedRevision'] ?? '', $diff['baselineRevision'])
    || preg_match('/^[0-9a-f]{40}$/D', $diff['candidateRevision'] ?? '') !== 1) {
    throw new RuntimeException('API migration review requires the exact 2.1.2 comparator and complete property inventory.');
}
foreach (['breaking', 'changed', 'added'] as $section) {
    if (!is_array($diff[$section] ?? null) || !array_is_list($diff[$section])) {
        throw new RuntimeException('Invalid public API delta section: ' . $section);
    }
}

$expectedRemovals = $policy['sourceMethodRelocations'] ?? null;
$expectedSignatures = $policy['reviewedSignatureChanges'] ?? null;
if (!is_array($expectedRemovals) || !is_array($expectedSignatures)
    || !array_is_list($expectedSignatures)) {
    throw new RuntimeException('Invalid reviewed API migration policy.');
}
$actualRemovals = [];
foreach ($diff['breaking'] as $record) {
    if (!is_array($record) || ($record['type'] ?? null) !== 'removed_method'
        || !is_string($record['symbol'] ?? null)
        || !isset($expectedRemovals[$record['symbol']])
        || isset($actualRemovals[$record['symbol']])) {
        throw new RuntimeException('Unexpected removed or incompatible public declaration.');
    }
    $actualRemovals[$record['symbol']] = true;
}
$missingRemovals = array_diff(array_keys($expectedRemovals), array_keys($actualRemovals));
if ($missingRemovals !== []) {
    throw new RuntimeException('Previously reviewed trait relocation differs from the current delta.');
}

$expected = [];
foreach ($expectedSignatures as $record) {
    if (!is_array($record) || !is_string($record['symbol'] ?? null)
        || !preg_match('/^[0-9a-f]{64}$/D', $record['beforeSha256'] ?? '')
        || !preg_match('/^[0-9a-f]{64}$/D', $record['afterSha256'] ?? '')
        || isset($expected[$record['symbol']])) {
        throw new RuntimeException('Invalid or duplicate approved signature fingerprint.');
    }
    $expected[$record['symbol']] = [$record['beforeSha256'], $record['afterSha256']];
}
$actual = [];
foreach ($diff['changed'] as $record) {
    if (!is_array($record)) {
        throw new RuntimeException('Invalid changed declaration.');
    }
    if (($record['type'] ?? null) === 'source_changed') {
        continue;
    }
    $symbol = $record['symbol'] ?? null;
    if (($record['type'] ?? null) !== 'method_signature' || !is_string($symbol)
        || !is_string($record['before'] ?? null) || !is_string($record['after'] ?? null)
        || !isset($expected[$symbol]) || isset($actual[$symbol])) {
        throw new RuntimeException('Unreviewed method, property, enum or constant change.');
    }
    if (!hash_equals($expected[$symbol][0], hash('sha256', $record['before']))
        || !hash_equals($expected[$symbol][1], hash('sha256', $record['after']))) {
        throw new RuntimeException('Public signature changed since the approved review: ' . $symbol);
    }
    $actual[$symbol] = true;
}
if (array_diff(array_keys($expected), array_keys($actual)) !== []) {
    throw new RuntimeException('Approved public signatures are not all represented.');
}

foreach ($diff['added'] as $record) {
    if (!is_array($record) || ($record['type'] ?? null) !== 'added_type'
        || !is_string($record['symbol'] ?? null)) {
        throw new RuntimeException('Unsupported new public API declaration class.');
    }
}
if (($diff['breakingCount'] ?? null) !== count($diff['breaking'])
    || ($diff['changeCount'] ?? null) !== count($diff['changed'])
    || ($diff['additionCount'] ?? null) !== count($diff['added'])) {
    throw new RuntimeException('Source-only API delta counts are inconsistent.');
}

echo json_encode([
    'reviewed' => true,
    'sourceRevision' => $diff['candidateRevision'],
    'traitRelocations' => count($actualRemovals),
    'reviewedSignatureChanges' => count($actual),
    'addedTypes' => count($diff['added']),
    'behavioralCompatibilityCertified' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
