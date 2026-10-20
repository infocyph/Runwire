<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/** @return array{p50_us: float, p95_us: float, p99_us: float, mean_us: float} */
function r2PressurePercentiles(array $measurements): array
{
    sort($measurements, SORT_NUMERIC);
    $count = count($measurements);

    return [
        'p50_us' => round($measurements[(int) floor(($count - 1) * 0.50)], 3),
        'p95_us' => round($measurements[(int) floor(($count - 1) * 0.95)], 3),
        'p99_us' => round($measurements[(int) floor(($count - 1) * 0.99)], 3),
        'mean_us' => round(array_sum($measurements) / $count, 3),
    ];
}

/** @return array{elapsed_us: float, peak_ready: int, resumes: int, spawned: int, cancelled: int, active: int, timers: int, watchers: int} */
function r2PressureExercise(string $mode, int $tasks): array
{
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $pair = null;
    if ($mode === 'readable_wait') {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!is_array($pair) || count($pair) !== 2) {
            throw new RuntimeException('R2 socket pair is unavailable; I/O wait cannot be profiled.');
        }
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);
    }

    $start = hrtime(true);
    try {
        $completed = $runtime->run(function (CoroutineScope $scope) use ($mode, $tasks, $pair): int {
            if ($mode === 'readable_wait') {
                $reader = $scope->spawn(function () use ($scope, $pair): string {
                    $scope->waitReadable($pair[0]);

                    return (string) fread($pair[0], 1);
                });
                $scope->spawn(function () use ($scope, $pair): void {
                    $scope->yieldNow();
                    if (fwrite($pair[1], 'x') !== 1) {
                        throw new RuntimeException('R2 socket-pair write was incomplete.');
                    }
                });

                return $reader->await() === 'x' ? 1 : 0;
            }

            $children = [];
            for ($index = 0; $index < $tasks; ++$index) {
                $children[] = $scope->spawn(function () use ($scope, $mode): int {
                    if ($mode === 'yield_resume') {
                        $scope->yieldNow();
                        $scope->yieldNow();
                    } elseif ($mode === 'sleep_wake') {
                        $scope->sleep(0.0005);
                    } elseif ($mode === 'cancel_sleep') {
                        $scope->sleep(30.0);
                    }

                    return 1;
                });
            }
            if ($mode === 'cancel_sleep') {
                $scope->yieldNow();
                foreach ($children as $child) {
                    $child->cancel(CancellationReason::HOST_CANCELLED);
                }
            }

            $completed = 0;
            foreach ($children as $child) {
                try {
                    $completed += (int) $child->await();
                } catch (CancelledException $error) {
                    if ($mode !== 'cancel_sleep') {
                        throw $error;
                    }
                    ++$completed;
                }
            }

            return $completed;
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
    if ($metrics->activeTasks !== 0 || $metrics->failedTotal !== 0
        || $metrics->spawnedTotal !== $actualTasks + 1
        || $metrics->completedTotal + $metrics->cancelledTotal !== $metrics->spawnedTotal
        || $metrics->readyQueueMaxDepth > $metrics->maxReadyBacklog
        || $loopMetrics->timersActive !== 0
        || $loopMetrics->readWatchers !== 0
        || $loopMetrics->writeWatchers !== 0) {
        throw new RuntimeException('R2 scheduler leaked work, watchers, timers or exceeded its bounded ready queue.');
    }

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

/** @return array<string, mixed> */
function runwireR2SchedulerPressure(int $iterations = 6, int $warmup = 2, array $sizes = [8, 32, 128]): array
{
    if ($iterations < 1 || $iterations > 100 || $warmup < 0 || $warmup > 20
        || $sizes === [] || count($sizes) > 5) {
        throw new InvalidArgumentException('R2 profiling iterations and task sizes must be bounded.');
    }
    foreach ($sizes as $tasks) {
        if (!is_int($tasks) || $tasks < 1 || $tasks > 512) {
            throw new InvalidArgumentException('R2 profiling task size must be between 1 and 512.');
        }
    }

    $results = [];
    foreach (['spawn_join', 'yield_resume', 'sleep_wake', 'cancel_sleep', 'readable_wait'] as $mode) {
        foreach ($sizes as $tasks) {
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
            $key = $mode . '-' . $tasks;
            $results[$key] = [
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
    }

    return [
        'schema' => 1,
        'profile' => 'r2-standalone-scheduler-pressure',
        'php_version' => PHP_VERSION,
        'runtime_build' => getenv('RUNWIRE_RUNTIME_BUILD') ?: 'local-unpinned',
        'warmup' => $warmup,
        'iterations' => $iterations,
        'modes' => $results,
        'peak_php_allocation_bytes' => memory_get_peak_usage(true),
        'correctness_passed' => count($results) === count($sizes) * 5,
        'real_network_included' => false,
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $result = runwireR2SchedulerPressure((int) ($argv[1] ?? 6), (int) ($argv[2] ?? 2));
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
}
