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
    <<<'PHP'
    private int $bodyBytes = 0;

    private bool $bodySuppressed = false;
PHP,
    <<<'PHP'
    private int $bodyBytes = 0;

    private string $benchmarkPendingHead = '';

    private bool $bodySuppressed = false;
PHP,
    'property',
);

$source = replaceOnce(
    $source,
    <<<'PHP'
        $result = $this->connection->write($this->serializeHead($status, $fields));
        if (!$result->accepted()) {
PHP,
    <<<'PHP'
        $head = $this->serializeHead($status, $fields);
        if (getenv('RUNWIRE_BENCH_H1_COALESCE') === '1') {
            $this->benchmarkPendingHead = $head;
            $result = new WriteResult(WriteState::ACCEPTED, $this->connection->pendingWriteBytes());
        } else {
            $result = $this->connection->write($head);
        }
        if (!$result->accepted()) {
PHP,
    'start write',
);

$source = replaceOnce(
    $source,
    <<<'PHP'
    private function closedResult(): WriteResult
    {
PHP,
    <<<'PHP'
    private function benchmarkWrite(string $wire): WriteResult
    {
        if ($this->benchmarkPendingHead !== '') {
            $wire = $this->benchmarkPendingHead . $wire;
            $this->benchmarkPendingHead = '';
        }

        return $this->connection->write($wire);
    }

    private function closedResult(): WriteResult
    {
PHP,
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
    <<<'PHP'
        $result = $finalChunk === ''
            ? $this->connection->write('')
            : $this->connection->write($finalChunk);
PHP,
    '        $result = $this->benchmarkWrite($finalChunk);',
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

fwrite(STDOUT, 'Patched HTTP/1 benchmark coalescing variant.' . PHP_EOL);
