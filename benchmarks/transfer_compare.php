<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\ResponseTransfer;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use RuntimeException;

require dirname(__DIR__) . '/vendor/autoload.php';

final class TransferBenchmarkWriter implements ResponseWriterInterface
{
    private readonly LoopInterface $loop;

    private readonly int $pressureEvery;

    public int $bytes = 0;

    private $drainCallback = null;

    private bool $ended = false;

    private bool $started = false;

    private int $writes = 0;

    public function __construct(
        LoopInterface $loop,
        int $pressureEvery,
    ) {
        $this->loop = $loop;
        $this->pressureEvery = $pressureEvery;
    }

    public function end(string $finalChunk = ''): WriteResult
    {
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }

        $this->started = true;
        $this->bytes += strlen($finalChunk);
        $this->ended = true;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function onDrain(callable $callback): self
    {
        $this->drainCallback = $callback;

        return $this;
    }

    public function onTerminal(callable $callback): self
    {
        if ($this->ended) {
            $callback($this);
        }

        return $this;
    }

    public function start(int $status = 200, ?Headers $headers = null): WriteResult
    {
        unset($status, $headers);
        $this->started = true;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    public function write(string $chunk): WriteResult
    {
        if ($this->ended) {
            return new WriteResult(WriteState::CLOSED, 0);
        }

        $this->started = true;
        $this->bytes += strlen($chunk);
        ++$this->writes;

        if ($this->pressureEvery > 0 && $this->writes % $this->pressureEvery === 0) {
            $this->loop->defer(function (): void {
                $callback = $this->drainCallback;
                $this->drainCallback = null;
                if ($callback !== null) {
                    $callback($this);
                }
            });

            return new WriteResult(WriteState::PRESSURED, 65_536);
        }

        return new WriteResult(WriteState::ACCEPTED, 0);
    }
}

/** @return resource */
function transferBenchmarkSource(int $bytes): mixed
{
    $source = tmpfile();
    if (!is_resource($source)) {
        throw new RuntimeException('Unable to create response-transfer benchmark source.');
    }

    $chunk = str_repeat('x', 65_536);
    $remaining = $bytes;
    while ($remaining > 0) {
        $write = min($remaining, strlen($chunk));
        if (fwrite($source, substr($chunk, 0, $write)) !== $write) {
            fclose($source);
            throw new RuntimeException('Unable to populate response-transfer benchmark source.');
        }
        $remaining -= $write;
    }
    rewind($source);

    return $source;
}

function manualTransfer(
    CoroutineScope $scope,
    mixed $source,
    ResponseWriterInterface $writer,
    int $chunkBytes,
    int $chunksPerTurn,
): int {
    if (!stream_set_blocking($source, false)) {
        throw new RuntimeException('Unable to make manual benchmark source non-blocking.');
    }

    $transferred = 0;
    $chunksThisTurn = 0;

    try {
        while (true) {
            $scope->cancellation()->throwIfCancelled();
            $chunk = fread($source, $chunkBytes);
            if ($chunk === false) {
                throw new RuntimeException('Unable to read manual benchmark source.');
            }
            if ($chunk === '') {
                if (feof($source)) {
                    $result = $writer->end();
                    if (!$result->accepted()) {
                        throw new RuntimeException('Manual benchmark failed to end response.');
                    }

                    return $transferred;
                }

                $scope->waitReadable($source);

                continue;
            }

            $result = $writer->write($chunk);
            if (!$result->accepted()) {
                throw new RuntimeException('Manual benchmark write was rejected.');
            }
            $transferred += strlen($chunk);

            if ($result->pressured()) {
                $deferred = $scope->deferred();
                $writer->onDrain(static function () use ($deferred): void {
                    if (!$deferred->future()->isComplete()) {
                        $deferred->resolve(null);
                    }
                });
                $deferred->future()->await();
                $chunksThisTurn = 0;

                continue;
            }

            ++$chunksThisTurn;
            if ($chunksThisTurn >= $chunksPerTurn) {
                $chunksThisTurn = 0;
                $scope->yieldNow();
            }
        }
    } finally {
        if (is_resource($source)) {
            fclose($source);
        }
    }
}

/** @param list<float> $values */
function transferMedian(array $values): float
{
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $middle = intdiv($count, 2);

    return $count % 2 === 1
        ? $values[$middle]
        : ($values[$middle - 1] + $values[$middle]) / 2;
}

function transferTrial(bool $helper, int $bytes): float
{
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $writer = new TransferBenchmarkWriter($loop, 16);
    $source = transferBenchmarkSource($bytes);
    $started = hrtime(true);

    $transferred = $runtime->run(static function (CoroutineScope $scope) use ($helper, $source, $writer): int {
        return $helper
            ? ResponseTransfer::stream($scope, $source, $writer, 65_536, 8)
            : manualTransfer($scope, $source, $writer, 65_536, 8);
    });

    $elapsed = (hrtime(true) - $started) / 1_000_000_000;
    if ($transferred !== $bytes || $writer->bytes !== $bytes || !$writer->isEnded()) {
        throw new RuntimeException('Response-transfer benchmark produced incorrect byte accounting.');
    }

    return ($bytes / 1_048_576) / $elapsed;
}

$bytes = 8 * 1_048_576;
$helperRates = [];
$manualRates = [];

for ($trial = 0; $trial < 5; ++$trial) {
    if ($trial % 2 === 0) {
        $helperRates[] = transferTrial(true, $bytes);
        $manualRates[] = transferTrial(false, $bytes);
    } else {
        $manualRates[] = transferTrial(false, $bytes);
        $helperRates[] = transferTrial(true, $bytes);
    }
}

$helperMedian = transferMedian($helperRates);
$manualMedian = transferMedian($manualRates);
$deltaPercent = (($helperMedian - $manualMedian) / $manualMedian) * 100.0;
$passed = $deltaPercent >= -5.0;

$result = [
    'payload_bytes' => $bytes,
    'trials' => 5,
    'chunk_bytes' => 65_536,
    'chunks_per_turn' => 8,
    'pressure_every_writes' => 16,
    'helper_median_mib_per_second' => round($helperMedian, 3),
    'manual_median_mib_per_second' => round($manualMedian, 3),
    'delta_percent' => round($deltaPercent, 3),
    'regression_budget_percent' => 5.0,
    'correctness_passed' => true,
    'passed' => $passed,
];

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

if (!$passed) {
    throw new RuntimeException('Bounded response-transfer helper exceeds the 5% manual-pump regression budget.');
}
