<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Source audit tools require the PHP CLI SAPI.');
}

/**
 * Compare two immutable source-only PHP API manifests (no Composer required).
 *
 * php tools/public-api-diff.php < manifest-envelope.json
 *
 * This checks declarations only; behavioral compatibility needs human review.
 */

/** Two source-only manifests are passed as a bounded stdin envelope. */
if (!is_array($argv ?? null) || count($argv) !== 1) {
    throw new InvalidArgumentException('Usage: php tools/public-api-diff.php < manifest-envelope.json');
}
$input = stream_get_contents(STDIN, 33_600_001);
if (!is_string($input) || $input === '' || strlen($input) > 33_600_000) {
    throw new InvalidArgumentException('API diff input is missing or exceeds 33.6 MiB.');
}
$envelope = json_decode($input, true, 64, JSON_THROW_ON_ERROR);
if (!is_array($envelope) || array_is_list($envelope)) {
    throw new InvalidArgumentException('API diff requires a JSON document envelope.');
}

/**
 * @param mixed $values
 * @return list<string>
 */
$readList = static function (mixed $values): array {
    if (!is_array($values) || !array_is_list($values)) {
        throw new InvalidArgumentException('Expected a list of public API declarations.');
    }
    $result = [];
    foreach ($values as $value) {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Public API declarations must be strings.');
        }
        $result[] = $value;
    }

    return $result;
};

/**
 * @param mixed $values
 * @return array<string,string>
 */
$readMethods = static function (mixed $values): array {
    if (!is_array($values)) {
        throw new InvalidArgumentException('Expected a public method signature map.');
    }
    $result = [];
    foreach ($values as $name => $signature) {
        if (!is_string($name) || !is_string($signature)) {
            throw new InvalidArgumentException('Public method names and signatures must be strings.');
        }
        $result[$name] = $signature;
    }

    return $result;
};

/**
 * @return array{sourceRevision:string,symbols:array<string,array{kind:string,methods:array<string,string>,publicProperties:?array<string,string>,publicConstants:list<string>,enumCases:list<string>,sha256:string}>}
 */
$load = static function (mixed $decoded) use ($readList, $readMethods): array {
    if (!is_array($decoded) || ($decoded['schemaVersion'] ?? null) !== 1
        || !is_array($decoded['symbols'] ?? null)
        || !is_string($decoded['sourceRevision'] ?? null)
        || preg_match('/^[0-9a-f]{40}$/D', $decoded['sourceRevision']) !== 1) {
        throw new InvalidArgumentException('API diff requires source-only manifest schema version 1.');
    }

    $symbols = [];
    foreach ($decoded['symbols'] as $name => $record) {
        if (!is_string($name) || !is_array($record)
            || !is_string($record['kind'] ?? null)
            || !is_string($record['sha256'] ?? null)) {
            throw new InvalidArgumentException('Invalid public API symbol record.');
        }
        $symbols[$name] = [
            'kind' => $record['kind'],
            'sha256' => $record['sha256'],
            'methods' => $readMethods($record['methods'] ?? []),
            'publicProperties' => array_key_exists('publicProperties', $record)
                ? $readMethods($record['publicProperties'])
                : null,
            'publicConstants' => $readList($record['publicConstants'] ?? []),
            'enumCases' => $readList($record['enumCases'] ?? []),
        ];
    }

    return ['sourceRevision' => $decoded['sourceRevision'], 'symbols' => $symbols];
};

$baseline = $load($envelope['baseline'] ?? null);
$candidate = $load($envelope['candidate'] ?? null);
$breaking = [];
$added = [];
$changed = [];

foreach ($baseline['symbols'] as $name => $old) {
    $next = $candidate['symbols'][$name] ?? null;
    if ($next === null) {
        $breaking[] = ['type' => 'removed_type', 'symbol' => $name];

        continue;
    }
    if ($old['kind'] !== $next['kind']) {
        $breaking[] = [
            'type' => 'changed_kind',
            'symbol' => $name,
            'before' => $old['kind'],
            'after' => $next['kind'],
        ];
    }
    foreach ($old['methods'] as $method => $signature) {
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

    // Older pinned source manifests have no property inventory. Compare
    // property changes only when both revisions explicitly carry it.
    if ($old['publicProperties'] !== null && $next['publicProperties'] !== null) {
        foreach ($old['publicProperties'] as $property => $signature) {
            $current = $next['publicProperties'][$property] ?? null;
            if ($current === null) {
                $breaking[] = ['type' => 'removed_property', 'symbol' => $name . '::$' . $property];
            } elseif ($current !== $signature) {
                $changed[] = [
                    'type' => 'property_declaration',
                    'symbol' => $name . '::$' . $property,
                    'before' => $signature,
                    'after' => $current,
                ];
            }
        }
    }
    foreach (['publicConstants' => 'constant', 'enumCases' => 'enum_case'] as $field => $kind) {
        foreach (array_diff($old[$field], $next[$field]) as $value) {
            $changed[] = [
                'type' => 'removed_or_changed_' . $kind,
                'symbol' => $name,
                'before' => $value,
            ];
        }
    }
    if ($old['sha256'] !== $next['sha256']) {
        $changed[] = ['type' => 'source_changed', 'symbol' => $name];
    }
}

foreach (array_keys($candidate['symbols']) as $name) {
    if (!array_key_exists($name, $baseline['symbols'])) {
        $added[] = ['type' => 'added_type', 'symbol' => $name];
    }
}

/**
 * @param array<string, mixed> $left
 * @param array<string, mixed> $right
 */
$bySymbol = static function (array $left, array $right): int {
    $leftSymbol = $left['symbol'] ?? null;
    $leftType = $left['type'] ?? null;
    $rightSymbol = $right['symbol'] ?? null;
    $rightType = $right['type'] ?? null;
    if (!is_string($leftSymbol) || !is_string($leftType)
        || !is_string($rightSymbol) || !is_string($rightType)) {
        throw new InvalidArgumentException('API diff entries must have string symbol and type.');
    }

    return strcmp($leftSymbol . ':' . $leftType, $rightSymbol . ':' . $rightType);
};
usort($breaking, $bySymbol);
usort($changed, $bySymbol);
usort($added, $bySymbol);

$output = json_encode([
    'schemaVersion' => 1,
    'baselineRevision' => $baseline['sourceRevision'],
    'candidateRevision' => $candidate['sourceRevision'],
    'breakingCount' => count($breaking),
    'changeCount' => count($changed),
    'additionCount' => count($added),
    'propertyInventoryCompared' => !array_filter($baseline['symbols'], static fn(array $symbol): bool => $symbol['publicProperties'] === null)
        && !array_filter($candidate['symbols'], static fn(array $symbol): bool => $symbol['publicProperties'] === null),
    'breaking' => $breaking,
    'changed' => $changed,
    'added' => $added,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents('php://stdout', $output . PHP_EOL) === false) {
    throw new RuntimeException('Unable to write the public API diff.');
}
