<?php

declare(strict_types=1);

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\BoundedConnectionPool;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\PoolIsolationKey;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Independent real TCP requests through new sockets versus actual retained pooled sockets. */
final class U10PoolNetworkTrial
{
    private readonly Dialer $dialer;

    private readonly ?PoolIsolationKey $key;

    private readonly SelectLoop $loop;

    private readonly ?BoundedConnectionPool $pool;

    private int $active = 0;

    private int $completed = 0;

    private ?Throwable $failure = null;

    private int $issued = 0;

    /** @var list<float> */
    private array $latencies = [];

    private int $maxFds = 0;

    private int $maxRss = 0;

    /** @var array<int, array{started:int, lease:?int, connection:?Connection, owner:?object, received:string}> */
    private array $requests = [];

    public function __construct(
        private readonly int $port,
        private readonly int $count,
        private readonly int $concurrency,
        private readonly bool $pooled,
    ) {
        $this->loop = new SelectLoop();
        $this->dialer = new Dialer($this->loop);
        $this->pool = $pooled ? new BoundedConnectionPool(
            $this->loop,
            maxActive: $concurrency,
            maxWaiting: $concurrency,
            maxIdle: $concurrency,
            enableMetrics: true,
        ) : null;
        $this->key = $pooled ? new PoolIsolationKey(
            DialEndpoint::parse('tcp://127.0.0.1:' . $port),
            'u10-independent-measurement',
        ) : null;
    }

    /** @return array<string,int|float|bool> */
    public function run(): array
    {
        $startCpu = self::cpuMs();
        $started = hrtime(true);
        $watchdog = $this->loop->delay(20.0, function (): void {
            $this->fail(new RuntimeException('U10 real socket workload exceeded bounded deadline.'));
        });
        try {
            $this->fill();
            $this->loop->run();
            if ($this->failure !== null) {
                throw $this->failure;
            }
            if ($this->completed !== $this->count || $this->requests !== []) {
                throw new RuntimeException('U10 real socket workload did not finish all requests.');
            }
            $elapsed = max(0.000001, (hrtime(true) - $started) / 1e9);
            $this->sampleResources();
            sort($this->latencies);
            $latencyIndex = max(0, (int) ceil(count($this->latencies) * 0.99) - 1);
            $metrics = $this->pool?->metrics();
            $reuse = is_array($metrics) ? (int) $metrics['reuse_hits'] : 0;
            $fresh = is_array($metrics) ? (int) $metrics['fresh_dial_slots'] : $this->count;

            return [
                'successful_requests' => $this->completed,
                'real_network_io' => true,
                'concurrency' => $this->concurrency,
                'rps' => round($this->completed / $elapsed, 3),
                'p99_ms' => round($this->latencies[$latencyIndex], 3),
                'cpu_parent_ms' => round(max(0, self::cpuMs() - $startCpu), 3),
                'rss_bytes' => $this->maxRss,
                'peak_open_fds' => $this->maxFds,
                'reuse_hits' => $reuse,
                'fresh_dials' => $fresh,
                'reuse_hit_rate' => $this->completed > 0 ? round($reuse / $this->completed, 4) : 0.0,
            ];
        } finally {
            $this->loop->cancel($watchdog);
            foreach ($this->requests as $request) {
                $request['connection']?->abort();
            }
            $this->pool?->close();
            $this->dialer->close();
            $diagnostics = $this->loop->diagnostics();
            if ($diagnostics->readWatchers !== 0 || $diagnostics->writeWatchers !== 0
                || $diagnostics->timersActive !== 0) {
                throw new RuntimeException('U10 measured workload leaked event-loop resources.');
            }
        }
    }

    private static function cpuMs(): float
    {
        $usage = getrusage();

        return 1000.0 * (
            ($usage['ru_utime.tv_sec'] ?? 0) + ($usage['ru_stime.tv_sec'] ?? 0)
        ) + (
            ($usage['ru_utime.tv_usec'] ?? 0) + ($usage['ru_stime.tv_usec'] ?? 0)
        ) / 1000.0;
    }

    private function connected(int $id, ?Connection $connection, ?Throwable $error): void
    {
        if ($error !== null || $connection === null) {
            $this->fail($error ?? new RuntimeException('Independent outbound TCP dial failed.'));

            return;
        }
        if (!isset($this->requests[$id])) {
            $connection->abort();

            return;
        }
        $owner = new stdClass();
        $this->requests[$id]['connection'] = $connection;
        $this->requests[$id]['owner'] = $owner;
        try {
            $connection->claimCallbacks(
                $owner,
                function (Connection $live) use ($id): void {
                    $this->received($id, $live);
                },
                function (Connection $live): void {
                    if ($live->state() === \Infocyph\Runwire\Network\Enum\ConnectionState::CLOSED) {
                        $this->fail(new RuntimeException('Independent pooled socket closed during response send.'));
                    }
                },
                function () use ($id): void {
                    $this->fail(new RuntimeException('Independent pool peer ended an unfinished request ' . $id));
                },
            );
            if (!$connection->write("ping\n")->accepted()) {
                throw new RuntimeException('Independent pool test request exceeded outbound quota.');
            }
            $this->sampleResources();
        } catch (Throwable $failure) {
            $this->fail($failure);
        }
    }

    private function fail(Throwable $error): void
    {
        $this->failure ??= $error;
        $this->loop->stop();
    }

    private function fill(): void
    {
        if ($this->failure !== null) {
            return;
        }
        while ($this->active < $this->concurrency && $this->issued < $this->count) {
            $id = ++$this->issued;
            ++$this->active;
            $this->requests[$id] = [
                'started' => hrtime(true), 'lease' => null, 'connection' => null,
                'owner' => null, 'received' => '',
            ];
            if ($this->pool !== null) {
                $this->pool->acquire(
                    $this->key,
                    function (int $lease, ?Connection $connection, ?Throwable $error) use ($id): void {
                        $this->requests[$id]['lease'] = $lease;
                        if ($error !== null) {
                            $this->fail($error);

                            return;
                        }
                        if ($connection !== null) {
                            $this->connected($id, $connection, null);

                            return;
                        }
                        $this->open($id);
                    },
                );
            } else {
                $this->open($id);
            }
        }
    }

    private function open(int $id): void
    {
        $this->dialer->connect(
            'tcp://127.0.0.1:' . $this->port,
            function (?Connection $connection, ?Throwable $error) use ($id): void {
                $this->connected($id, $connection, $error);
            },
        );
    }

    private function received(int $id, Connection $connection): void
    {
        $request = $this->requests[$id] ?? null;
        if ($request === null) {
            return;
        }
        $bytes = $request['received'] . $connection->read();
        if (!str_starts_with("pong\n", $bytes)) {
            $this->fail(new RuntimeException('Independent origin reply did not match expected bytes.'));

            return;
        }
        $this->requests[$id]['received'] = $bytes;
        if ($bytes !== "pong\n") {
            return;
        }
        $owner = $request['owner'];
        if (!is_object($owner)) {
            $this->fail(new RuntimeException('Pool response lost its callback owner.'));

            return;
        }
        $connection->releaseCallbacks($owner);
        if ($this->pool !== null && $request['lease'] !== null) {
            $this->pool->release($request['lease'], $connection);
        } else {
            $connection->abort();
        }
        $this->latencies[] = max(0.0, (hrtime(true) - $request['started']) / 1e6);
        unset($this->requests[$id]);
        ++$this->completed;
        --$this->active;
        $this->sampleResources();
        if ($this->completed === $this->count) {
            $this->loop->stop();

            return;
        }
        $this->loop->defer(function (): void {
            $this->fill();
        });
    }

    private function sampleResources(): void
    {
        $files = glob('/proc/self/fd/*');
        $this->maxFds = max($this->maxFds, is_array($files) ? count($files) : 0);
        $status = file_get_contents('/proc/self/status');
        if (is_string($status) && preg_match('/^VmRSS:\s+([0-9]+)\s+kB/m', $status, $matches) === 1) {
            $this->maxRss = max($this->maxRss, (int) $matches[1] * 1024);
        }
    }
}

/** @param list<float> $values */
function u10Median(array $values): float
{
    sort($values);
    $count = count($values);

    return $count % 2 === 0
        ? ($values[intdiv($count, 2) - 1] + $values[intdiv($count, 2)]) / 2
        : $values[intdiv($count, 2)];
}

if ($argc !== 3 || filter_var($argv[1], FILTER_VALIDATE_INT) === false
    || filter_var($argv[2], FILTER_VALIDATE_INT) === false) {
    throw new InvalidArgumentException('Usage: php u10_pool_live_profile.php <requests 32..1024> <concurrency 4..32>');
}
$count = (int) $argv[1];
$concurrency = (int) $argv[2];
if ($count < 32 || $count > 1024 || $concurrency < 4 || $concurrency > 32 || $count < $concurrency * 4) {
    throw new InvalidArgumentException('U10 measurement workload must contain at least four saturation waves.');
}

$process = proc_open(
    ['python3', dirname(__DIR__) . '/tests/fixtures/independent_pool_echo_server.py'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
if (!is_resource($process)) {
    throw new RuntimeException('Independent U10 TCP origin could not start.');
}
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
try {
    $ready = '';
    $started = microtime(true);
    while (!str_contains($ready, "\n") && microtime(true) - $started < 4) {
        $ready .= stream_get_contents($pipes[1]);
        if (str_contains($ready, "\n") || !proc_get_status($process)['running']) {
            break;
        }
        usleep(10_000);
    }
    if (preg_match('/^READY ([0-9]+)\n/', $ready, $matches) !== 1) {
        throw new RuntimeException('Independent pool origin failed to bind: ' . stream_get_contents($pipes[2]));
    }
    $port = (int) $matches[1];
    $trials = ['direct', 'pool', 'pool', 'direct'];
    $results = ['direct' => [], 'pool' => []];
    foreach ($trials as $mode) {
        $results[$mode][] = (new U10PoolNetworkTrial($port, $count, $concurrency, $mode === 'pool'))->run();
    }
    $summary = [];
    foreach ($results as $mode => $rows) {
        $summary[$mode] = [
            'successful_requests' => array_sum(array_column($rows, 'successful_requests')),
            'rps' => round(u10Median(array_column($rows, 'rps')), 3),
            'p99_ms' => round(u10Median(array_column($rows, 'p99_ms')), 3),
            'cpu_parent_ms' => round(u10Median(array_column($rows, 'cpu_parent_ms')), 3),
            'rss_bytes' => max(array_column($rows, 'rss_bytes')),
            'peak_open_fds' => max(array_column($rows, 'peak_open_fds')),
            'reuse_hits' => array_sum(array_column($rows, 'reuse_hits')),
            'fresh_dials' => array_sum(array_column($rows, 'fresh_dials')),
            'reuse_hit_rate' => round(
                array_sum(array_column($rows, 'reuse_hits')) / (2 * $count),
                4,
            ),
            'trials' => $rows,
        ];
    }
    fwrite(STDOUT, json_encode([
        'schema' => 1,
        'real_network_io' => true,
        'independent_tcp_origin' => true,
        'development_profile_not_release_certification' => true,
        'requests_per_trial' => $count,
        'concurrency' => $concurrency,
        'trial_order' => $trials,
        'direct' => $summary['direct'],
        'pooled' => $summary['pool'],
        'pooled_vs_direct_rps_ratio' => round($summary['pool']['rps'] / max($summary['direct']['rps'], 0.000001), 4),
        'pooled_vs_direct_p99_ratio' => round($summary['pool']['p99_ms'] / max($summary['direct']['p99_ms'], 0.000001), 4),
        'correctness_passed' => $summary['direct']['successful_requests'] === 2 * $count
            && $summary['pool']['successful_requests'] === 2 * $count
            && $summary['pool']['reuse_hits'] > 0,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
} finally {
    proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}
