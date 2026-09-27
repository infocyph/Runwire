<?php

declare(strict_types=1);

if ($argc !== 2) {
    throw new InvalidArgumentException('Usage: php patch_h1_coalesce.php <runwire-root>');
}

$root = rtrim($argv[1], '/\\');
$path = $root . '/src/Http/Http1/Http1ResponseWriter.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Unable to read Http1ResponseWriter.php.');
}

/**
 * @param string $search
 * @param string $replacement
 */
function replaceOnce(string $source, string $search, string $replacement, string $label): string
{
    $count = substr_count($source, $search);
    if ($count !== 1) {
        throw new RuntimeException(sprintf('%s patch expected exactly one anchor, found %d.', $label, $count));
    }

    return str_replace($search, $replacement, $source);
}

$source = replaceOnce(
    $source,
    "    private int \$bodyBytes = 0;\n\n    private bool \$bodySuppressed = false;",
    "    private int \$bodyBytes = 0;\n\n    private string \$benchmarkPendingHead = '';\n\n    private bool \$bodySuppressed = false;",
    'property',
);

$source = replaceOnce(
    $source,
    "        \$result = \$this->connection->write(\$this->serializeHead(\$status, \$fields));\n        if (!\$result->accepted()) {",
    "        \$head = \$this->serializeHead(\$status, \$fields);\n        if (getenv('RUNWIRE_BENCH_H1_COALESCE') === '1') {\n            \$this->benchmarkPendingHead = \$head;\n            \$result = new WriteResult(WriteState::ACCEPTED, \$this->connection->pendingWriteBytes());\n        } else {\n            \$result = \$this->connection->write(\$head);\n        }\n        if (!\$result->accepted()) {",
    'start write',
);

$source = replaceOnce(
    $source,
    "    private function closedResult(): WriteResult\n    {",
    "    private function benchmarkWrite(string \$wire): WriteResult\n    {\n        if (\$this->benchmarkPendingHead !== '') {\n            \$wire = \$this->benchmarkPendingHead . \$wire;\n            \$this->benchmarkPendingHead = '';\n        }\n\n        return \$this->connection->write(\$wire);\n    }\n\n    private function closedResult(): WriteResult\n    {",
    'benchmark write helper',
);

$source = replaceOnce(
    $source,
    "            return \$this->finish(\$this->connection->write(''));",
    "            return \$this->finish(\$this->benchmarkWrite(''));",
    'suppressed body flush',
);

$wireWrites = substr_count($source, '$result = $this->connection->write($wire);');
if ($wireWrites !== 2) {
    throw new RuntimeException(sprintf('wire write patch expected two anchors, found %d.', $wireWrites));
}
$source = str_replace(
    '$result = $this->connection->write($wire);',
    '$result = $this->benchmarkWrite($wire);',
    $source,
);

$source = replaceOnce(
    $source,
    "        \$result = \$finalChunk === ''\n            ? \$this->connection->write('')\n            : \$this->connection->write(\$finalChunk);",
    "        \$result = \$this->benchmarkWrite(\$finalChunk);",
    'fixed-length end',
);

$source = replaceOnce(
    $source,
    "        return \$this->started ? \$this->connection->write('') : \$this->start();",
    "        return \$this->started ? \$this->benchmarkWrite('') : \$this->start();",
    'empty write',
);

if (file_put_contents($path, $source, LOCK_EX) === false) {
    throw new RuntimeException('Unable to write patched Http1ResponseWriter.php.');
}

fwrite(STDOUT, "Patched HTTP/1 benchmark coalescing variant.\n");
