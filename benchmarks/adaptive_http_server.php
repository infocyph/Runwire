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
        'Usage: php adaptive_http_server.php <http1|h2> <port> <auto|fixed> <payload-bytes> [certificate private-key]',
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
if (!in_array($mode, [AdaptivePolicyMode::AUTO, AdaptivePolicyMode::FIXED], true)) {
    throw new InvalidArgumentException('Adaptive benchmark mode must be auto or fixed.');
}

$policy = new AdaptiveProtocolPolicy(mode: $mode);
$fixed = new AdaptiveProtocolPolicy(mode: AdaptivePolicyMode::FIXED);
$tls = null;
if ($protocol === 'h2') {
    if ($argc !== 7) {
        throw new InvalidArgumentException('HTTP/2 adaptive benchmark requires certificate and private-key paths.');
    }
    $tls = new TlsOptions($argv[5], $argv[6], alpnProtocols: ['h2']);
} elseif ($argc !== 5) {
    throw new InvalidArgumentException('HTTP/1.1 adaptive benchmark does not accept TLS paths.');
}

$body = str_repeat('x', $payloadBytes);
$server = new Server(
    name: 'adaptive-' . $protocol,
    address: '127.0.0.1:' . $port,
    handler: static function (HttpRequest $request, ResponseWriterInterface $writer) use ($body): void {
        if ($request->method !== 'GET' || $request->target !== '/benchmark') {
            $writer->end('bad');

            return;
        }

        $writer->end($body);
    },
    workers: 1,
    tls: $tls,
    http1: new Http1Limits(adaptive: $protocol === 'http1' ? $policy : $fixed),
    http2: new Http2Limits(adaptive: $protocol === 'h2' ? $policy : $fixed),
);

Runtime::create(new RuntimeOptions(driver: RuntimeDriver::NATIVE))
    ->listen($server)
    ->run();
