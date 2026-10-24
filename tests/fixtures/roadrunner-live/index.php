<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialEndpoint;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\IdleConnectionPool;
use Infocyph\Runwire\Network\PoolIsolationKey;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;

$diagnosticPath = sys_get_temp_dir() . '/runwire-road-runner-worker-error.log';
file_put_contents($diagnosticPath, sprintf(
    "BOOT pid=%d RR_MODE=%s RR_RELAY=%s%s",
    getmypid(),
    (string) getenv('RR_MODE'),
    (string) getenv('RR_RELAY'),
    PHP_EOL,
), FILE_APPEND);

try {
    require dirname(__DIR__, 3) . '/vendor/autoload.php';

    $sequence = 0;
    $previousTempPath = null;
    $lastRequestFds = null;
    Runtime::create(new RuntimeOptions(driver: RuntimeDriver::ROADRUNNER))->serve(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$sequence, &$previousTempPath, &$lastRequestFds): void {
            ++$sequence;
            $priorClean = $previousTempPath === null || !file_exists($previousTempPath);
            $beforeFds = count(glob('/proc/self/fd/*') ?: []);
            $priorFdStable = $lastRequestFds === null || $beforeFds <= $lastRequestFds;
            $path = tempnam(sys_get_temp_dir(), 'runwire-rr-scope-');
            if (!is_string($path)) {
                throw new RuntimeException('RoadRunner fixture could not allocate a temporary file.');
            }
            $previousTempPath = $path;
            $file = null;
            $pair = null;
            $fileRoundtrip = false;
            $socketRoundtrip = false;
            $poolRoundtrip = false;
            $poolClosed = false;
            $poolWatchersClean = false;
            $pool = null;
            $poolConnection = null;
            $poolPair = null;
            $poolLoop = null;

            try {
                $file = fopen($path, 'c+b');
                if (!is_resource($file)) {
                    throw new RuntimeException('RoadRunner fixture could not open its request file.');
                }
                $fileRoundtrip = fwrite($file, 'rr-file') === 7
                    && fflush($file)
                    && fseek($file, 0) === 0
                    && fread($file, 7) === 'rr-file';
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                if (!is_array($pair) || count($pair) !== 2) {
                    throw new RuntimeException('RoadRunner fixture Unix socket-pair is unavailable.');
                }
                $socketRoundtrip = fwrite($pair[0], 'peer') === 4
                    && fread($pair[1], 4) === 'peer';

                // Real socket lease/reborrow in one request only; the next
                // persistent-worker request must have no inherited pool owner.
                $poolPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                if (!is_array($poolPair) || count($poolPair) !== 2) {
                    throw new RuntimeException('RoadRunner pooled socket-pair is unavailable.');
                }
                $poolLoop = new SelectLoop();
                $poolConnection = new Connection($poolLoop, $poolPair[0]);
                $pool = new IdleConnectionPool($poolLoop, maxIdle: 1, idleTtlSeconds: 1.0);
                $poolKey = new PoolIsolationKey(
                    DialEndpoint::parse('unix:///tmp/runwire-rr-scoped-pool.sock'),
                    'request-' . $sequence,
                );
                $poolRoundtrip = $pool->store($poolKey, $poolConnection)
                    && $pool->take($poolKey) === $poolConnection
                    && $pool->store($poolKey, $poolConnection)
                    && $pool->count() === 1;
            } finally {
                $pool?->close();
                $poolClosed = $poolConnection?->state() === ConnectionState::CLOSED;
                $poolConnection?->abort();
                if (is_array($poolPair)) {
                    foreach ($poolPair as $poolStream) {
                        if (is_resource($poolStream)) {
                            fclose($poolStream);
                        }
                    }
                }
                $poolWatchersClean = $poolLoop === null || (
                    $poolLoop->diagnostics()->readWatchers === 0
                    && $poolLoop->diagnostics()->writeWatchers === 0
                    && $poolLoop->diagnostics()->timersActive === 0
                );
                if (is_resource($file)) {
                    fclose($file);
                }
                if (is_array($pair)) {
                    foreach ($pair as $stream) {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                }
                if (is_file($path)) {
                    unlink($path);
                }
            }
            clearstatcache(true, $path);
            $fileClean = !is_resource($file) && !is_file($path);
            $socketClean = is_array($pair)
                && !is_resource($pair[0])
                && !is_resource($pair[1]);
            $afterFds = count(glob('/proc/self/fd/*') ?: []);
            $fdStable = $afterFds <= $beforeFds;
            $lastRequestFds = $afterFds;

            $data = json_encode([
                'method' => $request->method,
                'target' => $request->target,
                'pid' => getmypid(),
                'sequence' => $sequence,
                'prior_file_clean' => $priorClean,
                'fd_before' => $beforeFds,
                'fd_after' => $afterFds,
                'fd_stable' => $fdStable,
                'prior_fd_stable' => $priorFdStable,
                'file_roundtrip' => $fileRoundtrip,
                'file_closed' => $fileClean,
                'socket_roundtrip' => $socketRoundtrip,
                'socket_closed' => $socketClean,
                'pool_roundtrip' => $poolRoundtrip,
                'pool_closed' => $poolClosed,
                'pool_watchers_clean' => $poolWatchersClean,
            ], JSON_THROW_ON_ERROR);
            $writer->start(200, Headers::fromArray([
                'content-type' => 'application/json',
                'content-length' => (string) strlen($data),
            ]));
            $writer->end($data);
        },
    );
} catch (Throwable $exception) {
    $message = sprintf('%s: %s%s', $exception::class, $exception->getMessage(), PHP_EOL);
    file_put_contents($diagnosticPath, $message, FILE_APPEND);
    fwrite(STDERR, $message);

    throw $exception;
}
