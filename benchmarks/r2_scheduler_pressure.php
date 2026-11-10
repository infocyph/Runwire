<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutinePolicy;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Exception\CoroutineOverflowException;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @param list<float> $measurements
 * @return array{p50_us: float, p95_us: float, p99_us: float, mean_us: float}
   * @throws \RuntimeException
 */
function r2PressurePercentiles(array $measurements): array
{
    if ($measurements === []) {
        throw new RuntimeException('R2 profiling requires measured latency samples.');
    }
    sort($measurements, SORT_NUMERIC);
    $count = count($measurements);

    return [
        'p50_us' => round($measurements[(int) floor((float) ($count - 1) * 0.50)], 3),
        'p95_us' => round($measurements[(int) floor((float) ($count - 1) * 0.95)], 3),
        'p99_us' => round($measurements[(int) floor((float) ($count - 1) * 0.99)], 3),
        'mean_us' => round(array_sum($measurements) / (float) $count, 3),
    ];
}

/**
 * @return array{resource,resource}|null
 * @throws RuntimeException
 */
function r2PressurePair(string $mode): ?array
{
    if ($mode !== 'readable_wait') {
        return null;
    }
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!is_array($pair) || !isset($pair[0], $pair[1])) {
        throw new RuntimeException('R2 socket pair is unavailable; I/O wait cannot be profiled.');
    }
    stream_set_blocking($pair[0], false);
    stream_set_blocking($pair[1], false);

    return [$pair[0], $pair[1]];
}

/**
 * @param array{resource,resource} $pair
 * @throws Throwable
 */
function r2PressureReadable(CoroutineScope $scope, array $pair): int
{
    $reader = $scope->spawn(static function () use ($scope, $pair): string {
        $scope->waitReadable($pair[0]);
        $data = fread($pair[0], 1);
        if ($data === false) {
            throw new RuntimeException('R2 socket-pair read failed.');
        }

        return $data;
    });
    $scope->spawn(static function () use ($scope, $pair): void {
        $scope->yieldNow();
        if (fwrite($pair[1], 'x') !== 1) {
            throw new RuntimeException('R2 socket-pair write was incomplete.');
        }
    });

    return $reader->await() === 'x' ? 1 : 0;
}

/** @throws Throwable */
function r2PressureTask(CoroutineScope $scope, string $mode): int
{
    if ($mode === 'yield_resume') {
        $scope->yieldNow();
        $scope->yieldNow();
    } elseif ($mode === 'sleep_wake') {
        $scope->sleep(0.0005);
    } elseif ($mode === 'cancel_sleep') {
        $scope->sleep(30.0);
    }

    return 1;
}

/**
 * @param list<\Infocyph\Runwire\Coroutine\Task> $children
 * @throws Throwable
 */
function r2PressureJoin(array $children, string $mode): int
{
    $completed = 0;
    foreach ($children as $child) {
        try {
            if ($child->await() !== 1) {
                throw new RuntimeException('R2 scheduler returned an invalid task checksum.');
            }
            ++$completed;
        } catch (CancelledException $error) {
            if ($mode !== 'cancel_sleep') {
                throw $error;
            }
            ++$completed;
        }
    }

    return $completed;
}

/** @throws Throwable */
function r2PressureSpawn(CoroutineScope $scope, string $mode, int $tasks): int
{
    $children = [];
    for ($index = 0; $index < $tasks; ++$index) {
        $children[] = $scope->spawn(static fn(): int => r2PressureTask($scope, $mode));
    }
    if ($mode === 'cancel_sleep') {
        $scope->yieldNow();
        foreach ($children as $child) {
            $child->cancel(CancellationReason::HOST_CANCELLED);
        }
    }

    return r2PressureJoin($children, $mode);
}

/** @throws RuntimeException */
function r2PressureAssertClean(
    \Infocyph\Runwire\Coroutine\CoroutineDiagnosticsSnapshot $metrics,
    \Infocyph\Runwire\Loop\LoopDiagnosticsSnapshot $loopMetrics,
    int $actualTasks,
): void {
    if ($metrics->activeTasks !== 0 || $metrics->failedTotal !== 0
        || $metrics->spawnedTotal !== $actualTasks + 1
        || $metrics->completedTotal + $metrics->cancelledTotal !== $metrics->spawnedTotal
        || $metrics->readyQueueMaxDepth > $metrics->maxReadyBacklog
        || $loopMetrics->timersActive !== 0
        || $loopMetrics->readWatchers !== 0
        || $loopMetrics->writeWatchers !== 0) {
        throw new RuntimeException('R2 scheduler leaked work, watchers, timers or exceeded its bounded ready queue.');
    }
}

/**
 * @return array{elapsed_us: float, peak_ready: int, resumes: int, spawned: int, cancelled: int, active: int, timers: int, watchers: int}
 * @throws \Throwable
 */
function r2PressureExercise(string $mode, int $tasks): array
{
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $pair = r2PressurePair($mode);

    $start = hrtime(true);

    try {
        $completed = $runtime->run(function (CoroutineScope $scope) use ($mode, $tasks, $pair): int {
            return $pair !== null
                ? r2PressureReadable($scope, $pair)
                : r2PressureSpawn($scope, $mode, $tasks);
        });
        if ($completed !== ($mode === 'readable_wait' ? 1 : $tasks)) {
            throw new RuntimeException('R2 scheduler produced incorrect task completion accounting.');
        }
    } finally {
        if ($pair !== null) {
            fclose($pair[0]);
            fclose($pair[1]);
        }
    }

    $metrics = $runtime->diagnostics();
    $loopMetrics = $loop->diagnostics();
    $actualTasks = $mode === 'readable_wait' ? 2 : $tasks;
    r2PressureAssertClean($metrics, $loopMetrics, $actualTasks);

    return [
        'elapsed_us' => (hrtime(true) - $start) / 1_000,
        'peak_ready' => $metrics->readyQueueMaxDepth,
        'resumes' => $metrics->resumesTotal,
        'spawned' => $metrics->spawnedTotal,
        'cancelled' => $metrics->cancelledTotal,
        'active' => $metrics->activeTasks,
        'timers' => $loopMetrics->timersActive,
        'watchers' => $loopMetrics->readWatchers + $loopMetrics->writeWatchers,
    ];
}

/**
 * @return array{limit: int, overflow_rejected: bool, active_after: int, max_ready: int}
 * @throws \Throwable
 */
function r2PressureOverloadBoundary(): array
{
    $limit = 8;
    $runtime = new CoroutineRuntime(policy: new CoroutinePolicy(
        maxTasks: $limit,
        maxReadyBacklog: $limit,
        maxResumesPerTick: 2,
    ));
    $rejected = false;

    try {
        $runtime->run(static function (CoroutineScope $scope) use ($limit): void {
            for ($index = 0; $index < $limit; ++$index) {
                $scope->spawn(static function (): int {
                    return 1;
                });
            }
        });
    } catch (CoroutineOverflowException) {
        $rejected = true;
    }
    $metrics = $runtime->diagnostics();
    if (!$rejected || $metrics->activeTasks !== 0
        || $metrics->readyQueueMaxDepth > $limit || $metrics->failedTotal !== 1) {
        throw new RuntimeException('R2 scheduler overload did not reject and safely clean up work.');
    }

    return [
        'limit' => $limit,
        'overflow_rejected' => true,
        'active_after' => $metrics->activeTasks,
        'max_ready' => $metrics->readyQueueMaxDepth,
    ];
}

/**
 * @param array<array-key,mixed> $sizes
 * @phpstan-assert list<int> $sizes
 * @psalm-assert list<int> $sizes
 * @throws InvalidArgumentException
 */
function r2PressureValidateSettings(int $iterations, int $warmup, array $sizes): void
{
    if ($iterations < 1 || $iterations > 100 || $warmup < 0 || $warmup > 20
        || $sizes === [] || count($sizes) > 5 || !array_is_list($sizes)) {
        throw new InvalidArgumentException('R2 profiling iterations and task sizes must be bounded.');
    }
    foreach ($sizes as $tasks) {
        if (!is_int($tasks) || $tasks < 1 || $tasks > 512) {
            throw new InvalidArgumentException('R2 profiling task size must be between 1 and 512.');
        }
    }
}

/**
 * @return array{operation:string,task_count:int,iterations:int,duration:array{p50_us:float,p95_us:float,p99_us:float,mean_us:float},peak_ready_depth:int,total_resumes:int,total_cancelled:int,active_after:int,watchers_after:int,timers_after:int}
 * @throws Throwable
 */
function r2PressureCell(string $mode, int $tasks, int $iterations, int $warmup): array
{
    $measurements = [];
    $depth = 0;
    $resumes = 0;
    $cancelled = 0;
    for ($trial = -$warmup; $trial < $iterations; ++$trial) {
        $sample = r2PressureExercise($mode, $tasks);
        if ($trial < 0) {
            continue;
        }
        $measurements[] = $sample['elapsed_us'];
        $depth = max($depth, $sample['peak_ready']);
        $resumes += $sample['resumes'];
        $cancelled += $sample['cancelled'];
    }

    return [
        'operation' => $mode,
        'task_count' => $mode === 'readable_wait' ? 2 : $tasks,
        'iterations' => $iterations,
        'duration' => r2PressurePercentiles($measurements),
        'peak_ready_depth' => $depth,
        'total_resumes' => $resumes,
        'total_cancelled' => $cancelled,
        'active_after' => 0,
        'watchers_after' => 0,
        'timers_after' => 0,
    ];
}

/**
 * @param array<array-key,mixed> $sizes
 * @return array{schema:int,profile:string,php_version:string,runtime_build:string,warmup:int,iterations:int,modes:array<string,array{operation:string,task_count:int,iterations:int,duration:array{p50_us:float,p95_us:float,p99_us:float,mean_us:float},peak_ready_depth:int,total_resumes:int,total_cancelled:int,active_after:int,watchers_after:int,timers_after:int}>,overload_boundary:array{limit:int,overflow_rejected:bool,active_after:int,max_ready:int},peak_php_allocation_bytes:int,correctness_passed:bool,real_network_included:bool}
 * @throws \InvalidArgumentException
 * @throws \Throwable
 */
function runwireR2SchedulerPressure(int $iterations = 6, int $warmup = 2, array $sizes = [8, 32, 128]): array
{
    r2PressureValidateSettings($iterations, $warmup, $sizes);

    $results = [];
    foreach (['spawn_join', 'yield_resume', 'sleep_wake', 'cancel_sleep', 'readable_wait'] as $mode) {
        foreach ($sizes as $tasks) {
            $results[$mode . '-' . $tasks] = r2PressureCell($mode, $tasks, $iterations, $warmup);
        }
    }

    return [
        'schema' => 1,
        'profile' => 'r2-standalone-scheduler-pressure',
        'php_version' => PHP_VERSION,
        'runtime_build' => r2PressureBuild(),
        'warmup' => $warmup,
        'iterations' => $iterations,
        'modes' => $results,
        'overload_boundary' => r2PressureOverloadBoundary(),
        'peak_php_allocation_bytes' => memory_get_peak_usage(true),
        'correctness_passed' => count($results) === count($sizes) * 5,
        'real_network_included' => false,
    ];
}

function r2PressureBuild(): string
{
    $build = getenv('RUNWIRE_RUNTIME_BUILD');

    return $build === false || $build === '' || $build === '0' ? 'local-unpinned' : $build;
}

$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptFilename) && realpath($scriptFilename) === __FILE__) {
    $result = runwireR2SchedulerPressure((int) ($argv[1] ?? 6), (int) ($argv[2] ?? 2));
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
}
