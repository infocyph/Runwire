<?php

declare(strict_types=1);

/**
 * Compare two immutable source-only PHP public API manifest exports.
 *
 * php tools/public-api-diff.php <2.1.2-manifest.json> <3.0-manifest.json>
 *
 * Output is reproducible JSON with breaking, changed and added declarations.
 * Behavioral changes still require separate maintainer review.
 */

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 3) {
    throw new InvalidArgumentException('Usage: php tools/public-api-diff.php <baseline.json> <candidate.json>');
}

$load = static function (string $file): array {
    if (!is_file($file) || !is_readable($file)) {
        throw new InvalidArgumentException('API diff manifest file is unavailable.');
    }
    $json = file_get_contents($file);
    if ($json === false || strlen($json) > 16_777_216) {
        throw new InvalidArgumentException('API diff manifest is unreadable or exceeds 16 MiB.');
    }
    $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || ($decoded['schemaVersion'] ?? null) !== 1
        || !is_array($decoded['symbols'] ?? null)
        || !is_string($decoded['sourceRevision'] ?? null)
        || preg_match('/^[0-9a-f]{40}$/D', $decoded['sourceRevision']) !== 1) {
        throw new InvalidArgumentException('API diff requires source-only manifest schema version 1.');
    }

    return $decoded;
};

$baseline = $load($arguments[1]);
$candidate = $load($arguments[2]);
$breaking = [];
$added = [];
$changed = [];

foreach ($baseline['symbols'] as $name => $old) {
    if (!is_array($old) || !is_string($name)) {
        throw new InvalidArgumentException('Invalid baseline public API type.');
    }
    $next = $candidate['symbols'][$name] ?? null;
    if ($next === null) {
        $breaking[] = ['type' => 'removed_type', 'symbol' => $name];

        continue;
    }
    if (!is_array($next)) {
        throw new InvalidArgumentException('Invalid candidate public API type.');
    }
    if (($old['kind'] ?? null) !== ($next['kind'] ?? null)) {
        $breaking[] = [
            'type' => 'changed_kind',
            'symbol' => $name,
            'before' => $old['kind'] ?? null,
            'after' => $next['kind'] ?? null,
        ];
    }
    foreach ($old['methods'] ?? [] as $method => $signature) {
        $current = $next['methods'][$method] ?? null;
        if ($current === null) {
            $breaking[] = ['type' => 'removed_method', 'symbol' => $name . '::' . $method];
        } elseif ($current !== $signature) {
            $changed[] = [
                'type' => 'method_signature',
                'symbol' => $name . '::' . $method,
                'before' => $signature,
                'after' => $current,
            ];
        }
    }
    foreach (['publicConstants' => 'constant', 'enumCases' => 'enum_case'] as $field => $kind) {
        $previous = $old[$field] ?? [];
        $current = $next[$field] ?? [];
        if (!is_array($previous) || !is_array($current)) {
            throw new InvalidArgumentException('Invalid API constant or enum case set.');
        }
        foreach (array_diff($previous, $current) as $value) {
            $changed[] = [
                'type' => 'removed_or_changed_' . $kind,
                'symbol' => $name,
                'before' => $value,
            ];
        }
    }
    if (($old['sha256'] ?? null) !== ($next['sha256'] ?? null)) {
        $changed[] = ['type' => 'source_changed', 'symbol' => $name];
    }
}

foreach ($candidate['symbols'] as $name => $new) {
    if (!array_key_exists($name, $baseline['symbols'])) {
        $added[] = ['type' => 'added_type', 'symbol' => $name];
    }
}

$bySymbol = static fn(array $left, array $right): int =>
    strcmp($left['symbol'] . ':' . $left['type'], $right['symbol'] . ':' . $right['type']);
usort($breaking, $bySymbol);
usort($changed, $bySymbol);
usort($added, $bySymbol);

echo json_encode([
    'schemaVersion' => 1,
    'baselineRevision' => $baseline['sourceRevision'],
    'candidateRevision' => $candidate['sourceRevision'],
    'breakingCount' => count($breaking),
    'changeCount' => count($changed),
    'additionCount' => count($added),
    'breaking' => $breaking,
    'changed' => $changed,
    'added' => $added,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
