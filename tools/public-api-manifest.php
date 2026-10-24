<?php

declare(strict_types=1);

/**
 * Reproducible SOURCE-ONLY API fingerprint. No Composer install and no optional
 * extension is needed to inspect the published tag. The manifest records
 * declarations, not hypothetical runtime availability.
 *
 * php tools/public-api-manifest.php <source-dir> <destination.json> <source-commit>
 */

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 4 || !is_dir($arguments[1])) {
    throw new InvalidArgumentException('Usage: php tools/public-api-manifest.php <src-directory> <output.json> <source-commit>');
}

[$command, $inputRoot, $output, $revision] = $arguments;
unset($command);
$root = realpath($inputRoot);
if ($root === false || !preg_match('/^[0-9a-f]{40}$/D', $revision)) {
    throw new InvalidArgumentException('Expected an existing source directory and immutable 40-character source SHA.');
}

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}
sort($files, SORT_STRING);
$symbols = [];
$unrecognized = [];
foreach ($files as $path) {
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException(sprintf('Cannot read PHP source: %s', $path));
    }
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));

    // One namespaced top-level PHP type per PSR-4 file is the source contract.
    // Report unexpected structures instead of silently omitting a public API.
    preg_match('/^namespace\s+([^;{]+)\s*[;{]/m', $source, $ns);
    preg_match_all(
        '/^\s*(?:(?:final|abstract|readonly)\s+)*(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)\b/m',
        $source,
        $decls,
        PREG_SET_ORDER,
    );
    if (count($decls) !== 1 || !isset($ns[1])) {
        $unrecognized[] = $relative;

        continue;
    }
    $namespace = trim($ns[1]);
    $kind = $decls[0][1];
    $name = $decls[0][2];
    $fqcn = $namespace . '\\' . $name;
    $methods = [];

    // Capture the public/protected method declaration verbatim up to its
    // outer body delimiter. Parentheses, attributes, quoted defaults and
    // property-promoted arguments cannot terminate a signature prematurely.
    preg_match_all(
        '/^\s*(?:(?:abstract|final)\s+)?(?:public|protected)\s+(?:static\s+)?function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/m',
        $source,
        $matches,
        PREG_OFFSET_CAPTURE,
    );
    foreach ($matches[1] as $index => $match) {
        $start = $matches[0][$index][1];
        $i = $start;
        $length = strlen($source);
        $round = 0;
        $square = 0;
        $quote = null;
        for (; $i < $length; ++$i) {
            $char = $source[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    ++$i;

                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;

                continue;
            }
            if ($char === '(') {
                ++$round;
            } elseif ($char === ')') {
                --$round;
            } elseif ($char === '[') {
                ++$square;
            } elseif ($char === ']') {
                --$square;
            } elseif (($char === '{' || $char === ';') && $round === 0 && $square === 0) {

                break;
            }
        }
        if ($i === $length || $round !== 0 || $square !== 0) {
            throw new RuntimeException(sprintf('Could not parse method %s::%s in %s', $fqcn, $match[0], $relative));
        }
        $signature = trim(substr($source, $start, $i - $start));
        $signature = (string) preg_replace('/\s+/', ' ', $signature);
        $methods[$match[0]] = $signature;
    }
    ksort($methods, SORT_STRING);

    $constants = [];
    preg_match_all('/^\s*public\s+const\s+[^;]+;/m', $source, $constantMatches);
    foreach ($constantMatches[0] as $constant) {
        $constants[] = trim((string) preg_replace('/\s+/', ' ', $constant));
    }
    sort($constants, SORT_STRING);

    $cases = [];
    if ($kind === 'enum') {
        preg_match_all('/^\s*case\s+[A-Za-z_][A-Za-z0-9_]*(?:\s*=\s*[^;]+)?;/m', $source, $caseMatches);
        foreach ($caseMatches[0] as $case) {
            $cases[] = trim((string) preg_replace('/\s+/', ' ', $case));
        }
        sort($cases, SORT_STRING);
    }

    $symbols[$fqcn] = [
        'kind' => $kind,
        'path' => $relative,
        'sha256' => hash('sha256', $source),
        'methods' => $methods,
        'publicConstants' => $constants,
        'enumCases' => $cases,
    ];
}
ksort($symbols, SORT_STRING);
$manifest = [
    'schemaVersion' => 1,
    'sourceRevision' => $revision,
    'sourceRoot' => 'src',
    'sourceFileCount' => count($files),
    'symbolCount' => count($symbols),
    'unrecognizedFiles' => $unrecognized,
    'symbols' => $symbols,
];
$encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents($output, $encoded . "\n") === false) {
    throw new RuntimeException(sprintf('Cannot write API manifest: %s', $output));
}
if ($unrecognized !== []) {
    throw new RuntimeException('Unrecognized source declarations: ' . implode(', ', $unrecognized));
}

fprintf(
    STDERR,
    'API manifest: source=%s files=%d symbols=%d sha256=%s' . PHP_EOL,
    $revision,
    count($files),
    count($symbols),
    hash('sha256', $encoded . PHP_EOL),
);
