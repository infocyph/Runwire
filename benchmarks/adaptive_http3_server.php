<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\AdaptiveProtocolPolicy;
use Infocyph\Runwire\Http\Enum\AdaptivePolicyMode;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Http3Options;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Worker;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicListener;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\TlsOptions;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 7) {
    throw new InvalidArgumentException(
        'Usage: php adaptive_http3_server.php <port> <certificate> <private-key> <ready-file> <auto|fixed> <payload-bytes>',
    );
}

$port = (int) $argv[1];
$certificate = $argv[2];
$privateKey = $argv[3];
$readyFile = $argv[4];
$mode = AdaptivePolicyMode::from(strtolower($argv[5]));
$payloadBytes = (int) $argv[6];
if ($port < 1 || $port > 65_535 || $payloadBytes < 1 || $payloadBytes > 1_048_576) {
    throw new InvalidArgumentException('Adaptive HTTP/3 benchmark port or payload size is invalid.');
}
if (!in_array($mode, [AdaptivePolicyMode::AUTO, AdaptivePolicyMode::FIXED], true)) {
    throw new InvalidArgumentException('Adaptive HTTP/3 benchmark mode must be auto or fixed.');
}

$defaults = new Http3Options();
$inboundPolicy = new AdaptiveProtocolPolicy(
    mode: $mode,
    lowWatermarkBasisPoints: $defaults->inboundAdaptive->lowWatermarkBasisPoints,
    highWatermarkBasisPoints: $defaults->inboundAdaptive->highWatermarkBasisPoints,
    transitionSamples: $defaults->inboundAdaptive->transitionSamples,
    ewmaNumerator: $defaults->inboundAdaptive->ewmaNumerator,
    ewmaDenominator: $defaults->inboundAdaptive->ewmaDenominator,
);
$outboundPolicy = new AdaptiveProtocolPolicy(
    mode: $mode,
    lowWatermarkBasisPoints: $defaults->outboundAdaptive->lowWatermarkBasisPoints,
    highWatermarkBasisPoints: $defaults->outboundAdaptive->highWatermarkBasisPoints,
    transitionSamples: $defaults->outboundAdaptive->transitionSamples,
    ewmaNumerator: $defaults->outboundAdaptive->ewmaNumerator,
    ewmaDenominator: $defaults->outboundAdaptive->ewmaDenominator,
);
$options = new Http3Options(
    limits: new Http3Limits(maxRequestStreamsPerConnection: 1_000_000),
    inboundAdaptive: $inboundPolicy,
    outboundAdaptive: $outboundPolicy,
);
$listener = PhpQuicListener::bind(
    '127.0.0.1',
    $port,
    $options->listenerOptions(new TlsOptions($certificate, $privateKey)),
);
$body = str_repeat('x', $payloadBytes);
$worker = new PhpQuicHttp3Worker(
    $listener,
    static function (HttpRequest $request, ResponseWriterInterface $writer) use ($body): void {
        $valid = $request->method === 'GET' && str_starts_with($request->target, '/benchmark');
        $response = $valid ? $body : 'bad';
        $status = $valid ? 200 : 400;

        $request->body->onEnd(static function () use ($writer, $response, $status): void {
            $writer->start($status, Headers::fromArray([
                'content-type' => 'application/octet-stream',
                'content-length' => (string) strlen($response),
            ]));
            $writer->end($response);
        });
    },
    $options->limits,
    16,
    handshakeTimeoutSeconds: $options->handshakeTimeoutSeconds,
    inboundAdaptive: $options->inboundAdaptive,
    outboundAdaptive: $options->outboundAdaptive,
);

if (!function_exists('pcntl_async_signals')) {
    throw new RuntimeException('Adaptive HTTP/3 benchmark requires pcntl signal support.');
}
$running = true;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$running): void {
    $running = false;
});
pcntl_signal(SIGINT, static function () use (&$running): void {
    $running = false;
});

if (file_put_contents($readyFile, 'ready', LOCK_EX) === false) {
    throw new RuntimeException('Unable to publish adaptive HTTP/3 benchmark readiness.');
}

while ($running) {
    $worker->tick($options->pollTimeoutSeconds);
}

$worker->stopAccepting();
$deadline = microtime(true) + 3.0;
while (!$worker->drainComplete() && microtime(true) < $deadline) {
    $worker->tick($options->pollTimeoutSeconds);
}
