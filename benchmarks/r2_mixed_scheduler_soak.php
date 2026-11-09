<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @param list<float> $samples
 * @throws \InvalidArgumentException
 */
function r2MixedPercentile(array $samples, float $percentile): float
{
    if ($samples === [] || $percentile < 0 || $percentile > 1) {
        throw new InvalidArgumentException('Mixed pressure percentiles require observations and a valid fraction.');
    }

    sort($samples, SORT_NUMERIC);

    return round($samples[(int) floor((float) (count($samples) - 1) * $percentile)], 3);
}
/** Owns the mutable progress observed by both socket and timer callbacks. */
final class R2MixedProgress
{
    public int $cpuCalls = 0;

    /** @var list<int> */
    public array $lastCpuTimerIds = [];

    /** @var list<float> */
    public array $latency = [];

    public int $maxPending = 0;

    /** @var list<int|float> */
    public array $pending = [];

    public int $received = 0;

    /** @var list<int> */
    public array $sampledMemory = [];

    public int $sent = 0;

    /** @throws RuntimeException */
    public function assertClean(\Infocyph\Runwire\Loop\LoopDiagnosticsSnapshot $diagnostics, int $cpuBurst): void
    {
        $pendingCount = count($this->pending);
        $correct = $this->sent >= 5
            && $this->received >= $this->sent - 1
            && $pendingCount <= 1
            && $this->cpuCalls >= ($this->sent - 1) * $cpuBurst
            && $diagnostics->timersActive === 0
            && $diagnostics->readWatchers === 0
            && $diagnostics->writeWatchers === 0
            && $diagnostics->deferredBacklog === 0
            && count($this->latency) >= 4;
        if (!$correct) {
            throw new RuntimeException(sprintf(
                'Mixed scheduler invariant failed: sent=%d read=%d pending=%d cpu=%d expected_cpu=%d timers=%d read_watchers=%d write_watchers=%d deferred=%d latency_samples=%d.',
                $this->sent,
                $this->received,
                $pendingCount,
                $this->cpuCalls,
                ($this->sent - 1) * $cpuBurst,
                $diagnostics->timersActive,
                $diagnostics->readWatchers,
                $diagnostics->writeWatchers,
                $diagnostics->deferredBacklog,
                count($this->latency),
            ));
        }
    }

    /** @param resource $stream
     * @throws RuntimeException
     */
    public function onReadable(mixed $stream): void
    {
        $chunk = fread($stream, 8_192);
        if (!is_string($chunk) || $chunk === '') {
            throw new RuntimeException('Mixed pressure network watcher lost a real socket response.');
        }
        $now = hrtime(true);
        for ($index = 0, $length = strlen($chunk); $index < $length; ++$index) {
            $timestamp = array_shift($this->pending);
            if (!is_int($timestamp) || $chunk[$index] !== 'x') {
                throw new RuntimeException('Mixed pressure socket response was out of order or corrupted.');
            }
            $this->latency[] = (float) ($now - $timestamp) / 1_000_000.0;
            ++$this->received;
        }
    }

    /** @param resource $writer
     * @throws RuntimeException
     * @throws \InvalidArgumentException
     */
    public function onTimer(SelectLoop $loop, mixed $writer, int $cpuBurst): void
    {
        if (count($this->pending) > 8) {
            throw new RuntimeException('Mixed pressure network backlog exceeded its safety bound.');
        }
        $timestamp = hrtime(true);
        if (fwrite($writer, 'x') !== 1) {
            throw new RuntimeException('Mixed pressure socket write was incomplete.');
        }
        $this->pending[] = $timestamp;
        ++$this->sent;
        $this->maxPending = max($this->maxPending, count($this->pending));
        $this->lastCpuTimerIds = [];
        for ($index = 0; $index < $cpuBurst; ++$index) {
            $this->lastCpuTimerIds[] = $loop->delay(0.0, function (): void {
                if (strlen(hash('sha256', 'runwire-mixed-scheduler-' . $this->cpuCalls)) !== 64) {
                    throw new RuntimeException('Mixed scheduler CPU digest did not complete.');
                }
                ++$this->cpuCalls;
            });
        }
        $this->sampledMemory[] = memory_get_usage(true);
    }
}

/**
 * @return array{cpu_callbacks_per_tick:int,socket_writes:int,socket_reads:int,pending_socket_bytes:int,max_pending_bytes:int,cpu_callbacks:int,max_sampled_php_bytes:int,memory_drift_bytes:int,watchers_after:int,timers_after:int,deferred_after:int,duration_seconds:float,readable_p50_ms:float,readable_p95_ms:float,readable_p99_ms:float,max_tick_ms:float,max_timer_lag_ms:float,correctness_passed:bool}
 * @throws \InvalidArgumentException
 * @throws \LogicException
 * @throws \OverflowException
 * @throws \RuntimeException
 * @throws \Throwable
 */
function r2MixedPressureTier(int $cpuBurst, float $durationSeconds): array
{
    if ($cpuBurst < 1 || $cpuBurst > 192 || $durationSeconds < 0.1
        || $durationSeconds > 60 || !is_finite($durationSeconds)) {
        throw new InvalidArgumentException('Mixed scheduler pressure requires bounded duration and work.');
    }

    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!is_array($pair) || count($pair) !== 2) {
        throw new RuntimeException('Unable to create local mixed pressure socket pair.');
    }

    [$reader, $writer] = $pair;
    stream_set_blocking($reader, false);
    stream_set_blocking($writer, false);
    $loop = new SelectLoop();
    $progress = new R2MixedProgress();
    $started = hrtime(true);

    $readWatcher = $loop->onReadable($reader, static function ($stream) use ($progress): void {
        $progress->onReadable($stream);
    });
    $timer = $loop->repeat(0.004, static function () use ($progress, $loop, $writer, $cpuBurst): void {
        $progress->onTimer($loop, $writer, $cpuBurst);
    });
    $stop = $loop->delay($durationSeconds, static function () use ($loop): void {
        $loop->stop();
    });

    try {
        $loop->run();
    } finally {
        $loop->cancel($timer);
        foreach ($progress->lastCpuTimerIds as $cpuTimerId) {
            $loop->cancel($cpuTimerId);
        }
        $loop->cancel($readWatcher);
        $loop->cancel($stop);
        fclose($reader);
        fclose($writer);
    }

    $diagnostics = $loop->diagnostics();
    $elapsed = (hrtime(true) - $started) / 1_000_000_000;
    $pendingCount = count($progress->pending);
    $progress->assertClean($diagnostics, $cpuBurst);

    $sampledMemory = $progress->sampledMemory;
    if ($sampledMemory === []) {
        throw new RuntimeException('Mixed scheduler did not capture any memory samples.');
    }
    $p99 = r2MixedPercentile($progress->latency, 0.99);
    if ($p99 > 500.0) {
        throw new RuntimeException('Mixed scheduler p99 socket progress exceeded 500 ms.');
    }

    return [
        'cpu_callbacks_per_tick' => $cpuBurst,
        'duration_seconds' => round($elapsed, 3),
        'socket_writes' => $progress->sent,
        'socket_reads' => $progress->received,
        'pending_socket_bytes' => $pendingCount,
        'max_pending_bytes' => $progress->maxPending,
        'cpu_callbacks' => $progress->cpuCalls,
        'readable_p50_ms' => r2MixedPercentile($progress->latency, 0.5),
        'readable_p95_ms' => r2MixedPercentile($progress->latency, 0.95),
        'readable_p99_ms' => $p99,
        'max_tick_ms' => round($diagnostics->maxTickNanoseconds / 1_000_000, 3),
        'max_timer_lag_ms' => round($diagnostics->maxLagNanoseconds / 1_000_000, 3),
        'max_sampled_php_bytes' => max($sampledMemory),
        'memory_drift_bytes' => max($sampledMemory) - min($sampledMemory),
        'watchers_after' => $diagnostics->readWatchers + $diagnostics->writeWatchers,
        'timers_after' => $diagnostics->timersActive,
        'deferred_after' => $diagnostics->deferredBacklog,
        'correctness_passed' => true,
    ];
}

/**
 * @return array{schema:int,profile:string,runtime_build:string,php_version:string,backend:string,tier_count:int,duration_seconds_per_tier:float,real_socket_io:bool,timers_and_cpu:bool,tiers:list<array{cpu_callbacks_per_tick:int,socket_writes:int,socket_reads:int,pending_socket_bytes:int,max_pending_bytes:int,cpu_callbacks:int,max_sampled_php_bytes:int,memory_drift_bytes:int,watchers_after:int,timers_after:int,deferred_after:int,duration_seconds:float,readable_p50_ms:float,readable_p95_ms:float,readable_p99_ms:float,max_tick_ms:float,max_timer_lag_ms:float,correctness_passed:bool}>,correctness_passed:bool}
 * @throws \InvalidArgumentException
 * @throws \LogicException
 * @throws \OverflowException
 * @throws \RuntimeException
 * @throws \Throwable
*/
function runwireR2MixedSchedulerSoak(float $durationSeconds = 2.0): array
{
    $tiers = [];
    foreach ([8, 32, 96] as $cpuBurst) {
        $tiers[] = r2MixedPressureTier($cpuBurst, $durationSeconds);
    }

    $build = getenv('RUNWIRE_RUNTIME_BUILD');

    return [
        'schema' => 1,
        'profile' => 'mixed-real-socket-timers-cpu',
        'runtime_build' => $build === false || $build === '' || $build === '0' ? 'local-unpinned' : $build,
        'php_version' => PHP_VERSION,
        'backend' => 'select-loop',
        'tier_count' => count($tiers),
        'duration_seconds_per_tier' => $durationSeconds,
        'real_socket_io' => true,
        'timers_and_cpu' => true,
        'tiers' => $tiers,
        'correctness_passed' => true,
    ];
}

$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptFilename) && realpath($scriptFilename) === __FILE__) {
    fwrite(STDOUT, json_encode(
        runwireR2MixedSchedulerSoak((float) ($argv[1] ?? 2.0)),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . PHP_EOL);
}
