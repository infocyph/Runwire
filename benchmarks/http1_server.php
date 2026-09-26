<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 2) {
    throw new InvalidArgumentException('Usage: php http1_server.php <port>');
}

$port = (int) $argv[1];
if ($port < 1 || $port > 65_535) {
    throw new InvalidArgumentException('Benchmark port must be between 1 and 65535.');
}

$server = Server::http(
    '127.0.0.1:' . $port,
    static function (HttpRequest $request, ResponseWriterInterface $writer): void {
        $valid = $request->method === 'GET' && str_starts_with($request->target, '/benchmark');
        $body = $valid ? 'ok' : 'bad';
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
)->withWorkers(1);

Runtime::create(new RuntimeOptions(driver: RuntimeDriver::NATIVE))
    ->listen($server)
    ->run();
