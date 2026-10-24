<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\AdaptiveProtocolPolicy;
use Infocyph\Runwire\Http\Enum\AdaptivePolicyMode;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 5 && $argc !== 7) {
    throw new InvalidArgumentException(
        'Usage: php adaptive_http_server.php <http1|h2> <port> <auto|fixed|latency|throughput> <payload-bytes> [certificate private-key]',
    );
}

$protocol = strtolower($argv[1]);
$port = (int) $argv[2];
$mode = AdaptivePolicyMode::from(strtolower($argv[3]));
$payloadBytes = (int) $argv[4];
if (!in_array($protocol, ['http1', 'h2'], true)) {
    throw new InvalidArgumentException('Adaptive benchmark protocol must be http1 or h2.');
}
if ($port < 1 || $port > 65_535 || $payloadBytes < 1 || $payloadBytes > 1_048_576) {
    throw new InvalidArgumentException('Adaptive benchmark port or payload size is invalid.');
}

$h1Defaults = (new Http1Limits())->adaptive;
$h2Defaults = (new Http2Limits())->adaptive;
$h1Policy = new AdaptiveProtocolPolicy(
    mode: $mode,
    lowWatermarkBasisPoints: $h1Defaults->lowWatermarkBasisPoints,
    highWatermarkBasisPoints: $h1Defaults->highWatermarkBasisPoints,
    transitionSamples: $h1Defaults->transitionSamples,
    ewmaNumerator: $h1Defaults->ewmaNumerator,
    ewmaDenominator: $h1Defaults->ewmaDenominator,
);
$h2Policy = new AdaptiveProtocolPolicy(
    mode: $mode,
    lowWatermarkBasisPoints: $h2Defaults->lowWatermarkBasisPoints,
    highWatermarkBasisPoints: $h2Defaults->highWatermarkBasisPoints,
    transitionSamples: $h2Defaults->transitionSamples,
    ewmaNumerator: $h2Defaults->ewmaNumerator,
    ewmaDenominator: $h2Defaults->ewmaDenominator,
);
$fixed = new AdaptiveProtocolPolicy(mode: AdaptivePolicyMode::FIXED);
$tls = null;
if ($argc === 7) {
    $tls = new TlsOptions($argv[5], $argv[6], alpnProtocols: [$protocol === 'h2' ? 'h2' : 'http/1.1']);
} elseif ($protocol === 'h2') {
    throw new InvalidArgumentException('HTTP/2 adaptive benchmark requires TLS paths.');
}

$body = $protocol === 'http1' && $payloadBytes === 2
    ? 'ok'
    : str_repeat('x', $payloadBytes);
$server = new Server(
    name: 'adaptive-' . $protocol,
    address: '127.0.0.1:' . $port,
    handler: static function (HttpRequest $request, ResponseWriterInterface $writer) use ($body): void {
        if ($request->method !== 'GET' || !preg_match('~^/benchmark(?:/(2|1024|16384|65536))?$~D', $request->target)) {
            $writer->end('bad');

            return;
        }

        $size = basename($request->target);
        $writer->end(ctype_digit($size) ? str_repeat('x', (int) $size) : $body);
    },
    workers: 1,
    tls: $tls,
    http1: new Http1Limits(
        adaptive: $protocol === 'http1' ? $h1Policy : $fixed,
    ),
    http2: new Http2Limits(
        adaptive: $protocol === 'h2' ? $h2Policy : $fixed,
    ),
);

Runtime::create(new RuntimeOptions(driver: RuntimeDriver::NATIVE))
    ->listen($server)
    ->run();
