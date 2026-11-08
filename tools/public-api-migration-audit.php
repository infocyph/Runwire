<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Source audit tools require the PHP CLI SAPI.');
}

/**
 * Certify source-only public API delta against an explicitly reviewed policy.
 *
 * Usage: php tools/public-api-migration-audit.php < audit-envelope.json
 *
 * The source parser cannot flatten traits and cannot prove behavioral BC.
 * Unexpected removed declarations or signature changes fail closed.
 */
if (!is_array($argv ?? null) || count($argv) !== 1) {
    throw new InvalidArgumentException('Usage: php tools/public-api-migration-audit.php < audit-envelope.json');
}
$serialized = stream_get_contents(STDIN, 4_300_001);
if (!is_string($serialized) || $serialized === '' || strlen($serialized) > 4_300_000) {
    throw new InvalidArgumentException('Public API audit input exceeds its bounded stdin limit.');
}
$envelope = json_decode($serialized, true, 32, JSON_THROW_ON_ERROR);
$diff = is_array($envelope) ? ($envelope['diff'] ?? null) : null;
$policy = is_array($envelope) ? ($envelope['policy'] ?? null) : null;
if (!is_array($diff) || !is_array($policy) || array_is_list($diff) || array_is_list($policy)) {
    throw new InvalidArgumentException('Invalid public API audit documents.');
}
$publishedRevision = $policy['publishedRevision'] ?? null;
$candidateRevision = $diff['candidateRevision'] ?? null;
if (($diff['schemaVersion'] ?? null) !== 1
    || ($policy['schemaVersion'] ?? null) !== 1
    || ($diff['propertyInventoryCompared'] ?? null) !== true
    || !is_string($diff['baselineRevision'] ?? null)
    || !is_string($publishedRevision)
    || !hash_equals($publishedRevision, $diff['baselineRevision'])
    || !is_string($candidateRevision)
    || preg_match('/^[0-9a-f]{40}$/D', $candidateRevision) !== 1) {
    throw new RuntimeException('API migration review requires the exact 2.1.2 comparator and complete property inventory.');
}
$breaking = $diff['breaking'] ?? null;
$changed = $diff['changed'] ?? null;
$added = $diff['added'] ?? null;
if (!is_array($breaking) || !array_is_list($breaking)
    || !is_array($changed) || !array_is_list($changed)
    || !is_array($added) || !array_is_list($added)) {
    throw new RuntimeException('Invalid public API delta sections.');
}

$expectedRemovals = $policy['sourceMethodRelocations'] ?? null;
$expectedSignatures = $policy['reviewedSignatureChanges'] ?? null;
if (!is_array($expectedRemovals) || !is_array($expectedSignatures)
    || !array_is_list($expectedSignatures)) {
    throw new RuntimeException('Invalid reviewed API migration policy.');
}
$actualRemovals = [];
foreach ($breaking as $record) {
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
        || !is_string($record['beforeSha256'] ?? null)
        || !is_string($record['afterSha256'] ?? null)
        || !preg_match('/^[0-9a-f]{64}$/D', $record['beforeSha256'])
        || !preg_match('/^[0-9a-f]{64}$/D', $record['afterSha256'])
        || isset($expected[$record['symbol']])) {
        throw new RuntimeException('Invalid or duplicate approved signature fingerprint.');
    }
    $expected[$record['symbol']] = [$record['beforeSha256'], $record['afterSha256']];
}
$actual = [];
foreach ($changed as $record) {
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

foreach ($added as $record) {
    if (!is_array($record) || ($record['type'] ?? null) !== 'added_type'
        || !is_string($record['symbol'] ?? null)) {
        throw new RuntimeException('Unsupported new public API declaration class.');
    }
}
if (($diff['breakingCount'] ?? null) !== count($breaking)
    || ($diff['changeCount'] ?? null) !== count($changed)
    || ($diff['additionCount'] ?? null) !== count($added)) {
    throw new RuntimeException('Source-only API delta counts are inconsistent.');
}

fwrite(STDOUT, json_encode([
    'reviewed' => true,
    'sourceRevision' => $diff['candidateRevision'],
    'traitRelocations' => count($actualRemovals),
    'reviewedSignatureChanges' => count($actual),
    'addedTypes' => count($added),
    'behavioralCompatibilityCertified' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
