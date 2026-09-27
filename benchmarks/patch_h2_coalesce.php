<?php

declare(strict_types=1);

if ($argc !== 2) {
    throw new InvalidArgumentException('Usage: php patch_h2_coalesce.php <runwire-root>');
}

$root = rtrim($argv[1], '/\\');
$path = $root . '/src/Http/Http2/Internal/ResponseScheduler.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Unable to read HTTP/2 ResponseScheduler.php.');
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
    "    /** @var array<int, true> */\n    private array \$flushQueue = [];",
    "    /** @var array<int, string> */\n    private array \$benchmarkPendingHeaderWire = [];\n\n    /** @var array<int, true> */\n    private array \$flushQueue = [];",
    'pending header property',
);

$source = replaceOnce(
    $source,
    "        \$this->wireQueue->clear();\n        \$this->flushQueue = [];",
    "        \$this->wireQueue->clear();\n        \$this->benchmarkPendingHeaderWire = [];\n        \$this->flushQueue = [];",
    'cleanup',
);

$source = replaceOnce(
    $source,
    "        unset(\$this->flushQueue[\$stream->id], \$this->pressuredStreams[\$stream->id]);",
    "        unset(\n            \$this->benchmarkPendingHeaderWire[\$stream->id],\n            \$this->flushQueue[\$stream->id],\n            \$this->pressuredStreams[\$stream->id],\n        );",
    'discard stream',
);

$source = replaceOnce(
    $source,
    "        \$result = \$this->sendFrame(new Frame(FrameType::DATA->value, 0x1, \$stream->id));",
    "        \$result = \$this->sendResponseFrame(\n            \$stream,\n            new Frame(FrameType::DATA->value, 0x1, \$stream->id),\n        );",
    'flush end',
);

$source = replaceOnce(
    $source,
    "        \$result = \$this->sendFrame(new Frame(FrameType::DATA->value, \$end ? 0x1 : 0, \$stream->id, \$chunk));",
    "        \$result = \$this->sendResponseFrame(\n            \$stream,\n            new Frame(FrameType::DATA->value, \$end ? 0x1 : 0, \$stream->id, \$chunk),\n        );",
    'flush data',
);

$source = replaceOnce(
    $source,
    "        \$pressured = false;\n        foreach (\$frames as \$frame) {",
    "        if (getenv('RUNWIRE_BENCH_H2_COALESCE') === '1') {\n            \$wire = '';\n            foreach (\$frames as \$frame) {\n                \$wire .= FrameWriter::encode(\$frame);\n            }\n            \$this->benchmarkPendingHeaderWire[\$stream->id] = \$wire;\n            (\$this->activityCallback)(\$stream);\n\n            return new WriteResult(WriteState::ACCEPTED, \$stream->outbound->bytes());\n        }\n\n        \$pressured = false;\n        foreach (\$frames as \$frame) {",
    'send headers',
);

$oldSendFrame = <<<'PHP'
    private function sendFrame(Frame $frame): WriteResult
    {
        $wire = FrameWriter::encode($frame);
        if (!$this->wireQueue->isEmpty()) {
            return $this->queueWire($wire);
        }
        $result = $this->connection->write($wire);
        if ($result->state === WriteState::REJECTED_LIMIT) {
            return $this->queueWire($wire);
        }
        if ($result->pressured()) {
            $this->transportPressured = true;
        }

        return $result;
    }
PHP;

$newSendFrame = <<<'PHP'
    private function sendFrame(Frame $frame): WriteResult
    {
        return $this->sendWire(FrameWriter::encode($frame));
    }

    private function sendResponseFrame(Http2Stream $stream, Frame $frame): WriteResult
    {
        $pending = $this->benchmarkPendingHeaderWire[$stream->id] ?? '';
        if ($pending === '') {
            return $this->sendFrame($frame);
        }

        unset($this->benchmarkPendingHeaderWire[$stream->id]);

        return $this->sendWire($pending . FrameWriter::encode($frame));
    }

    private function sendWire(string $wire): WriteResult
    {
        if (!$this->wireQueue->isEmpty()) {
            return $this->queueWire($wire);
        }
        $result = $this->connection->write($wire);
        if ($result->state === WriteState::REJECTED_LIMIT) {
            return $this->queueWire($wire);
        }
        if ($result->pressured()) {
            $this->transportPressured = true;
        }

        return $result;
    }
PHP;

$source = replaceOnce($source, $oldSendFrame, $newSendFrame, 'send frame');

if (file_put_contents($path, $source, LOCK_EX) === false) {
    throw new RuntimeException('Unable to write patched HTTP/2 ResponseScheduler.php.');
}

fwrite(STDOUT, 'Patched HTTP/2 benchmark coalescing variant.' . PHP_EOL);
