<?php

declare(strict_types=1);

require_once __DIR__ . '/http1_bench_workload.php';
require_once __DIR__ . '/cli_local_paths.php';

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;

if (!isset($argc, $argv)) {
    throw new \RuntimeException('CLI arguments are unavailable.');
}
if ($argc !== 5) {
    throw new InvalidArgumentException('Usage: tls_server.php <root> <port> <certificate> <key>');
}

$root = realpath($argv[1]);
if ($root === false) {
    throw new InvalidArgumentException('TLS server requires an existing local source directory.');
}
runwireBenchmarkCheckAutoload($root);
$port = (int) $argv[2];
$certificate = $argv[3];
$key = $argv[4];
if (!is_file($root . '/vendor/autoload.php') || $port < 1 || $port > 65_535
    || !is_file($certificate) || !is_file($key)) {
    throw new InvalidArgumentException('TLS server needs installed source, a port, certificate and key.');
}
require $root . '/vendor/autoload.php';

$benchmarkBody = http1BenchResponseBody();

$server = Server::http(
    '127.0.0.1:' . $port,
    static function (HttpRequest $request, ResponseWriterInterface $writer) use ($benchmarkBody): void {
        $valid = $request->method === 'GET' && str_starts_with($request->target, '/benchmark');
        $body = $valid ? $benchmarkBody : 'bad';
        $writer->start(
            $valid ? 200 : 400,
            Headers::fromArray([
                'content-type' => 'text/plain',
                'content-length' => (string) strlen($body),
            ]),
        );
        $writer->end($body);
    },
    'benchmark',
)->withTls(new TlsOptions(
    $certificate,
    $key,
    alpnProtocols: ['http/1.1'],
))->withWorkers(1);
Runtime::create(new RuntimeOptions(driver: RuntimeDriver::NATIVE))
    ->listen($server)
    ->run();
