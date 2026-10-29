<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\Host\HostRequestFactory;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\RuntimeContext;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Profiling-only hosted request path. Intentionally excludes the host network
 * reactor and does not instrument production request dispatch.
 *
 * @return array<string, mixed>
 */
function runwireR0HostProfile(int $iterations = 5_000, int $warmup = 1_000): array
{
    if ($iterations < 1 || $iterations > 100_000 || $warmup < 0 || $warmup > 10_000) {
        throw new InvalidArgumentException('R0 profile counts must be bounded and positive.');
    }

    $server = [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/profile?mode=host',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'HTTP_HOST' => 'localhost',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TRACE_ID' => 'r0-profile',
        'REMOTE_ADDR' => '127.0.0.1',
        'REMOTE_PORT' => '12345',
    ];
    $factory = new HostRequestFactory();
    $application = new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            $request->headers->first('x-trace-id');
            $writer->start(200, Headers::fromArray(['content-type' => 'text/plain']));
            $writer->end('ok');
        },
        runtimeContext: RuntimeContext::standalone(),
    );
    $samples = [
        'request_normalization' => [],
        'writer_allocation' => [],
        'dispatch_and_finalization' => [],
        'total' => [],
    ];
    $startUsage = memory_get_usage(true);
    $start = hrtime(true);
    $completed = 0;
    for ($i = -$warmup; $i < $iterations; ++$i) {
        $started = hrtime(true);
        $request = $factory->fromServer($server, '', 1_024);
        $normalized = hrtime(true);

        $status = null;
        $body = '';
        $endCalls = 0;
        $writer = new CallbackResponseWriter(
            static function (int $responseStatus, Headers $headers) use (&$status): void {
                if ($headers->first('content-type') !== 'text/plain') {
                    throw new RuntimeException('R0 host profile received unexpected response content type.');
                }
                $status = $responseStatus;
            },
            static function (string $chunk) use (&$body): void {
                $body .= $chunk;
            },
            static function () use (&$endCalls): void {
                ++$endCalls;
            },
            1_024,
        );
        $writerReady = hrtime(true);
        $application->handle($request, $writer, completeResponse: true);
        $handled = hrtime(true);

        if ($status !== 200 || $body !== 'ok' || $endCalls !== 1
            || !$writer->isEnded() || !$request->context->completed()) {
            throw new RuntimeException('R0 host profile detected incomplete or incorrect response ownership.');
        }
        if ($i < 0) {
            continue;
        }

        ++$completed;
        $samples['request_normalization'][] = ($normalized - $started) / 1_000;
        $samples['writer_allocation'][] = ($writerReady - $normalized) / 1_000;
        $samples['dispatch_and_finalization'][] = ($handled - $writerReady) / 1_000;
        $samples['total'][] = ($handled - $started) / 1_000;
    }

    $phases = [];
    foreach ($samples as $name => $durations) {
        sort($durations, SORT_NUMERIC);
        $length = count($durations);
        $phases[$name] = [
            'p50_us' => round($durations[(int) floor(($length - 1) * 0.50)], 3),
            'p95_us' => round($durations[(int) floor(($length - 1) * 0.95)], 3),
            'p99_us' => round($durations[(int) floor(($length - 1) * 0.99)], 3),
            'mean_us' => round(array_sum($durations) / $length, 3),
        ];
    }

    $peakRss = null;
    if (is_readable('/proc/self/status')) {
        $status = file_get_contents('/proc/self/status');
        if (is_string($status) && preg_match('/^VmHWM:\s*(\d+)\s+kB/m', $status, $match) === 1) {
            $peakRss = (int) $match[1] * 1_024;
        }
    }

    return [
        'schema' => 1,
        'profile' => 'host-synthetic-request-path',
        'runtime_build' => getenv('RUNWIRE_RUNTIME_BUILD') ?: 'local-unpinned',
        'php_version' => PHP_VERSION,
        'iterations' => $iterations,
        'warmup_iterations' => $warmup,
        'completed' => $completed,
        'correctness_passed' => $completed === $iterations,
        'host_network_included' => false,
        'phases' => $phases,
        'elapsed_seconds' => round((hrtime(true) - $start) / 1_000_000_000, 6),
        'php_allocation_delta_bytes' => memory_get_usage(true) - $startUsage,
        'php_peak_bytes' => memory_get_peak_usage(true),
        'process_peak_rss_bytes' => $peakRss,
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $iterations = (int) ($argv[1] ?? 5_000);
    $warmup = (int) ($argv[2] ?? 1_000);
    fwrite(STDOUT, json_encode(
        runwireR0HostProfile($iterations, $warmup),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . PHP_EOL);
}
