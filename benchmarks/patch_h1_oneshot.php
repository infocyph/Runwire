<?php

declare(strict_types=1);

if ($argc !== 2) {
    throw new InvalidArgumentException('Usage: php patch_h1_oneshot.php <runwire-root>');
}

$root = rtrim($argv[1], '/\\');
$path = $root . '/src/Http/Http1/Http1ResponseWriter.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Unable to read Http1ResponseWriter.php.');
}

function replaceOneShot(string $source, string $search, string $replacement, string $label): string
{
    $count = substr_count($source, $search);
    if ($count !== 1) {
        throw new RuntimeException(sprintf('%s patch expected exactly one anchor, found %d.', $label, $count));
    }

    return str_replace($search, $replacement, $source);
}

$source = replaceOneShot(
    $source,
    <<<'PHP'
        if (strlen($finalChunk) > $this->limits->maxResponseChunkBytes) {
            return $this->limitResult();
        }

        $start = $this->startForEndIfNeeded($finalChunk);
PHP,
    <<<'PHP'
        if (strlen($finalChunk) > $this->limits->maxResponseChunkBytes) {
            return $this->limitResult();
        }
        if (!$this->started) {
            $oneShot = $this->benchmarkEndOneShot($finalChunk);
            if ($oneShot !== null) {
                return $oneShot;
            }
        }

        $start = $this->startForEndIfNeeded($finalChunk);
PHP,
    'end fast path',
);

$source = replaceOneShot(
    $source,
    <<<'PHP'
    private function closedResult(): WriteResult
    {
PHP,
    <<<'PHP'
    private function benchmarkEndOneShot(string $finalChunk): ?WriteResult
    {
        $contentLength = strlen($finalChunk);
        $bodySuppressed = ResponseSemantics::suppressesBody($this->requestMethod === 'HEAD', 200);
        $fields = [new HeaderField('content-length', (string) $contentLength)];
        if ($this->closeAfter) {
            $fields[] = new HeaderField('connection', 'close');
        }

        $wire = $this->serializeHead(200, $fields);
        if (!$bodySuppressed) {
            $wire .= $finalChunk;
        }

        $result = $this->connection->write($wire);
        if ($result->state === WriteState::REJECTED_LIMIT) {
            return null;
        }
        if (!$result->accepted()) {
            return $result;
        }

        $this->started = true;
        $this->bodySuppressed = $bodySuppressed;
        $this->chunked = false;
        $this->contentLength = $contentLength;
        $this->bodyBytes = $contentLength;

        return $this->finish($result);
    }

    private function closedResult(): WriteResult
    {
PHP,
    'one-shot helper',
);

if (file_put_contents($path, $source, LOCK_EX) === false) {
    throw new RuntimeException('Unable to write patched Http1ResponseWriter.php.');
}

fwrite(STDOUT, 'Patched HTTP/1 implicit one-shot candidate.' . PHP_EOL);
