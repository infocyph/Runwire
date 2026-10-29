<?php

declare(strict_types=1);

use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http1OutboundClient;
use Infocyph\Runwire\Http\Client\Http2OutboundClient;
use Infocyph\Runwire\Http\Client\Http3OutboundClient;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
use Infocyph\Runwire\Network\QuicClientOptions;
use Infocyph\Runwire\Network\TlsClientOptions;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 6 || !in_array($argv[1], ['h1', 'h2', 'h3'], true)
    || !ctype_digit($argv[2]) || !ctype_digit($argv[3])
    || !ctype_digit($argv[5]) || !is_file($argv[4])) {
    throw new InvalidArgumentException('Expected protocol, TCP port, QUIC port, CA file and samples.');
}
$kind = $argv[1];
$count = (int) $argv[5];
if ($count < 16 || $count > 128) {
    throw new InvalidArgumentException('The matched profile requires 16–128 successful samples.');
}
$version = match ($kind) {
    'h1' => ProtocolVersion::HTTP_1_1,
    'h2' => ProtocolVersion::HTTP_2,
    'h3' => ProtocolVersion::HTTP_3,
};
$port = (int) ($kind === 'h3' ? $argv[3] : $argv[2]);
$request = new ClientRequest(
    'GET',
    'https://localhost:' . $port . '/interop?q=1',
    protocol: new ClientProtocolPolicy($version, [$version]),
);
$latencies = [];
$warmups = 4;
$before = getrusage();
$childrenBefore = getrusage(1);
$begin = hrtime(true);
for ($index = -$warmups; $index < $count; ++$index) {
    $loop = new SelectLoop();
    $status = 0;
    $received = '';
    $error = null;
    $completed = 0;
    $watchdog = $loop->delay(8.0, static function () use ($loop): void {
        $loop->stop();
    });
    $headers = static function (int $code) use (&$status): void {
        $status = $code;
    };
    $body = static function (string $part) use (&$received): void {
        $received .= $part;
    };
    if ($index === 0) {
        $before = getrusage();
        $childrenBefore = getrusage(1);
    }
    $start = hrtime(true);
    if ($kind === 'h3') {
        $client = Http3OutboundClient::connect(
            $loop,
            $request,
            new QuicClientOptions(
                'localhost',
                alpnProtocols: ['h3'],
                caFile: $argv[4],
                maxOpenStreams: 16,
                connectTimeoutMilliseconds: 4_000,
            ),
            $headers,
            $body,
            static function (?Throwable $failure) use (&$error, &$completed, $loop): void {
                $error = $failure;
                ++$completed;
                $loop->stop();
            },
        );
    } else {
        $executor = new ProcessExecutor($loop, 1, 8);
        $numeric = new Dialer($loop);
        $dialer = new HappyEyeballsDialer($loop, $numeric, new AsyncDnsResolver($executor));
        $alpn = $kind === 'h2' ? 'h2' : 'http/1.1';
        $options = new DialOptions(
            tls: new TlsClientOptions('localhost', caFile: $argv[4], alpnProtocols: [$alpn]),
        );
        $done = static function (?Connection $connection, ?Throwable $failure) use (
            &$error,
            &$completed,
            $loop,
        ): void {
            $error = $failure;
            ++$completed;
            $connection?->abort();
            $loop->stop();
        };
        if ($kind === 'h2') {
            Http2OutboundClient::connect($loop, $dialer, $request, $headers, $body, $done, $options);
        } else {
            Http1OutboundClient::connect($loop, $dialer, $request, $headers, $body, $done, $options);
        }
    }
    $loop->run();
    $elapsed = (hrtime(true) - $start) / 1_000_000;
    $loop->cancel($watchdog);
    if ($kind === 'h3') {
        $client->close();
    } else {
        $dialer->close();
        $numeric->close();
        $executor->close();
    }
    if ($error !== null || $completed !== 1 || $status !== 200
        || $received !== 'runwire-outbound-h3-peer-ok') {
        throw new RuntimeException('Independent outbound ' . $kind . ' did not produce an exact complete response.', 0, $error);
    }
    if ($index >= 0) {
        $latencies[] = $elapsed;
    }
}
$after = getrusage();
$childrenAfter = getrusage(1);
if (!is_array($before) || !is_array($after)
    || !is_array($childrenBefore) || !is_array($childrenAfter)) {
    throw new RuntimeException('Process CPU and RSS profiling is not available.');
}
$usage = static function (array $v): int {
    return (int) $v['ru_utime.tv_sec'] * 1_000_000
        + (int) $v['ru_utime.tv_usec']
        + (int) $v['ru_stime.tv_sec'] * 1_000_000
        + (int) $v['ru_stime.tv_usec'];
};
$parentCpuUs = max(0, $usage($after) - $usage($before));
$childCpuUs = max(0, $usage($childrenAfter) - $usage($childrenBefore));
$parentRssBytes = (int) ($after['ru_maxrss'] ?? 0) * 1024;
$childrenRssBytes = (int) ($childrenAfter['ru_maxrss'] ?? 0) * 1024;
sort($latencies, SORT_NUMERIC);
$seconds = array_sum($latencies) / 1_000;
if ($seconds <= 0.0 || count($latencies) !== $count) {
    throw new RuntimeException('Insufficient complete protocol samples.');
}
fwrite(STDOUT, json_encode([
    'schema' => 1,
    'protocol' => $kind,
    'source_sha' => getenv('RUNWIRE_RUNTIME_BUILD') ?: 'unknown',
    'php_version' => PHP_VERSION,
    'independent_peer' => $kind === 'h3' ? 'aioquic' : 'hyper-h2',
    'verified_tls_alpn' => true,
    'matching_body' => true,
    'concurrency' => 1,
    'warmups' => $warmups,
    'successful_requests' => $count,
    'errors' => 0,
    'successful_rps' => round($count / $seconds, 3),
    'successful_rpm' => round(60 * $count / $seconds, 3),
    'p99_ms' => round($latencies[(int) ceil($count * 0.99) - 1], 3),
    'parent_cpu_ms' => round($parentCpuUs / 1_000, 3),
    'reaped_child_cpu_ms' => round($childCpuUs / 1_000, 3),
    'parent_plus_reaped_child_cpu_ms' => round(($parentCpuUs + $childCpuUs) / 1_000, 3),
    'parent_peak_rss_bytes' => $parentRssBytes,
    'reaped_child_peak_rss_bytes' => $childrenRssBytes,
    'rss_scope' => 'parent_and_reaped_children_independent_peaks_not_additive',
    'worker_process_tree_certified' => false,
    'release_saturation_certified' => false,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
