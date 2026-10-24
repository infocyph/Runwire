<?php

declare(strict_types=1);

/**
 * Compare two immutable source-only PHP API manifests (no Composer required).
 *
 * php tools/public-api-diff.php <2.1.2-manifest.json> <3.0-manifest.json>
 *
 * This checks declarations only; behavioral compatibility needs human review.
 */

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 3) {
    throw new InvalidArgumentException('Usage: php tools/public-api-diff.php <baseline.json> <candidate.json>');
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
$load = static function (string $file) use ($readList, $readMethods): array {
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

$baseline = $load($arguments[1]);
$candidate = $load($arguments[2]);
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

foreach ($candidate['symbols'] as $name => $new) {
    if (!array_key_exists($name, $baseline['symbols'])) {
        $added[] = ['type' => 'added_type', 'symbol' => $name];
    }
}

/**
 * @param array{symbol:string,type:string} $left
 * @param array{symbol:string,type:string} $right
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
 . $property];
            } elseif ($current !== $signature) {
                $changed[] = [
                    'type' => 'property_declaration',
                    'symbol' => $name . '::, 'enumCases' => 'enum_case'] as $field => $kind) {
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

foreach ($candidate['symbols'] as $name => $new) {
    if (!array_key_exists($name, $baseline['symbols'])) {
        $added[] = ['type' => 'added_type', 'symbol' => $name];
    }
}

/**
 * @param array{symbol:string,type:string} $left
 * @param array{symbol:string,type:string} $right
 */
$bySymbol = static function (array $left, array $right): int {
    return strcmp($left['symbol'] . ':' . $left['type'], $right['symbol'] . ':' . $right['type']);
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
    'breaking' => $breaking,
    'changed' => $changed,
    'added' => $added,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents('php://stdout', $output . PHP_EOL) === false) {
    throw new RuntimeException('Unable to write the public API diff.');
}
 . $property,
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

foreach ($candidate['symbols'] as $name => $new) {
    if (!array_key_exists($name, $baseline['symbols'])) {
        $added[] = ['type' => 'added_type', 'symbol' => $name];
    }
}

/**
 * @param array{symbol:string,type:string} $left
 * @param array{symbol:string,type:string} $right
 */
$bySymbol = static function (array $left, array $right): int {
    return strcmp($left['symbol'] . ':' . $left['type'], $right['symbol'] . ':' . $right['type']);
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
    'breaking' => $breaking,
    'changed' => $changed,
    'added' => $added,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents('php://stdout', $output . PHP_EOL) === false) {
    throw new RuntimeException('Unable to write the public API diff.');
}
