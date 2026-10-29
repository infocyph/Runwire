<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http3OutboundClient;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\QuicClientOptions;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 4 || filter_var($argv[1], FILTER_VALIDATE_INT) === false
    || filter_var($argv[3], FILTER_VALIDATE_INT) === false) {
    throw new InvalidArgumentException('Usage: profile.php <udp-port> <trusted-ca> <iterations>');
}
$iterations = (int) $argv[3];
if ($iterations < 10 || $iterations > 64 || !is_file($argv[2])) {
    throw new InvalidArgumentException('Native H3 profile requires 10-64 repetitions and a real CA file.');
}
$port = (int) $argv[1];
if ($port < 1 || $port > 65_535) {
    throw new InvalidArgumentException('Invalid native QUIC endpoint.');
}

$policy = new ClientProtocolPolicy(
    preferred: ProtocolVersion::HTTP_3,
    allowedVersions: [ProtocolVersion::HTTP_3],
);
$options = new QuicClientOptions(
    'localhost',
    alpnProtocols: ['h3'],
    caFile: $argv[2],
    connectTimeoutMilliseconds: 4_000,
    maxOpenStreams: 16,
);
$request = new ClientRequest('GET', 'https://localhost:' . $port . '/interop?q=1', protocol: $policy);
$latencies = [];
$bytesTotal = 0;
$expected = 'runwire-outbound-h3-peer-ok';
$startUsage = getrusage();
$start = hrtime(true);

for ($index = 0; $index < $iterations; ++$index) {
    $loop = new SelectLoop();
    $completed = 0;
    $status = null;
    $contentType = null;
    $received = '';
    $failure = null;
    $opened = hrtime(true);
    $client = Http3OutboundClient::connect(
        $loop,
        $request,
        $options,
        static function (int $code, \Infocyph\Runwire\Http\Headers $headers) use (&$status, &$contentType): void {
            $status = $code;
            $contentType = $headers->first('content-type');
        },
        static function (string $bytes) use (&$received): void {
            $received .= $bytes;
        },
        static function (?Throwable $error) use (&$failure, &$completed): void {
            $failure = $error;
            ++$completed;
        },
    );
    $loop->run();
    $elapsed = (hrtime(true) - $opened) / 1_000_000;
    if ($failure !== null || $completed !== 1 || $status !== 200
        || $contentType !== 'text/plain' || $received !== $expected
        || $loop->diagnostics()->readWatchers !== 0
        || $loop->diagnostics()->writeWatchers !== 0
        || $loop->diagnostics()->timersActive !== 0) {
        throw new RuntimeException('Native independent HTTP/3 transport profile correctness or cleanup failed.', 0, $failure);
    }
    $client->close();
    $latencies[] = $elapsed;
    $bytesTotal += strlen($received);
}

$duration = (hrtime(true) - $start) / 1_000_000_000;
if ($duration <= 0.0) {
    throw new RuntimeException('Nonpositive native QUIC transport measurement duration.');
}
$endUsage = getrusage();
if (!is_array($startUsage) || !is_array($endUsage)) {
    throw new RuntimeException('Process CPU usage metrics are unavailable.');
}
sort($latencies, SORT_NUMERIC);
$index95 = (int) ceil(count($latencies) * 0.95) - 1;
$index99 = (int) ceil(count($latencies) * 0.99) - 1;
$cpuStart = (int) $startUsage['ru_utime.tv_sec'] * 1_000_000
    + (int) $startUsage['ru_utime.tv_usec']
    + (int) $startUsage['ru_stime.tv_sec'] * 1_000_000
    + (int) $startUsage['ru_stime.tv_usec'];
$cpuEnd = (int) $endUsage['ru_utime.tv_sec'] * 1_000_000
    + (int) $endUsage['ru_utime.tv_usec']
    + (int) $endUsage['ru_stime.tv_sec'] * 1_000_000
    + (int) $endUsage['ru_stime.tv_usec'];

fwrite(STDOUT, json_encode([
    'schema' => 1,
    'runtime_build' => getenv('RUNWIRE_RUNTIME_BUILD') ?: 'unknown',
    'php_version' => PHP_VERSION,
    'protocol' => 'h3',
    'implementation' => 'native-ext-quic-worker',
    'independent_peer' => 'aioquic',
    'real_network_io' => true,
    'verified_tls_alpn' => true,
    'workload' => 'serial-single-stream-get',
    'correctness_passed' => true,
    'concurrency' => 1,
    'requests_total' => $iterations,
    'successful_requests' => count($latencies),
    'errors_total' => 0,
    'duration_seconds' => $duration,
    'successful_rps' => round(count($latencies) / $duration, 3),
    'successful_rpm' => round(count($latencies) * 60 / $duration, 3),
    'p50_ms' => round($latencies[(int) floor((count($latencies) - 1) / 2)], 3),
    'p95_ms' => round($latencies[$index95], 3),
    'p99_ms' => round($latencies[$index99], 3),
    'peak_memory_bytes' => memory_get_peak_usage(true),
    'parent_cpu_ms' => round(max(0, $cpuEnd - $cpuStart) / 1000, 3),
    'total_body_bytes' => $bytesTotal,
    'producer_headroom_measured' => false,
    'full_end_to_end_certification' => false,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
