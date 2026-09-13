<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SwooleLoop;
use Infocyph\Runwire\Runtime\CoroutineRequestHandler;
use Infocyph\Runwire\Runtime\Driver\SwooleDriver;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\SwooleOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$port = isset($argv[1]) ? (int) $argv[1] : 0;
if ($port < 1 || $port > 65_535) {
    throw new InvalidArgumentException('A valid TCP port is required.');
}

$loop = new SwooleLoop();
$runtime = new CoroutineRuntime($loop);
$handler = new CoroutineRequestHandler(
    $runtime,
    static function (
        HttpRequest $request,
        ResponseWriterInterface $writer,
        CoroutineScope $scope,
    ) use ($loop): void {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('Unable to create the Swoole coroutine acceptance socket pair.');
        }

        [$left, $right] = $pair;
        stream_set_blocking($left, false);
        stream_set_blocking($right, false);

        try {
            $reader = $scope->spawn(function () use ($scope, $left): string {
                $scope->waitReadable($left);

                return (string) fread($left, 1);
            });
            $sender = $scope->spawn(function () use ($scope, $right): int {
                $scope->waitWritable($right);

                return fwrite($right, 's');
            });

            $scope->sleep(0.001);
            $payload = $reader->await();
            $written = $sender->await();
            $diagnostics = $loop->diagnostics();

            $writer->start(200, Headers::fromArray(['content-type' => 'text/plain']));
            $writer->end(sprintf(
                'runwire-swoole-ok:%s:%d:%d:%d:%d:%s',
                $payload,
                $written,
                $diagnostics->timersActive,
                $diagnostics->readWatchers,
                $diagnostics->writeWatchers,
                $request->target,
            ));
        } finally {
            fclose($left);
            fclose($right);
        }
    },
);
$application = new RuntimeApplication($handler);
$driver = new SwooleDriver(new SwooleOptions(
    host: '127.0.0.1',
    port: $port,
    workerCount: 1,
));
$driver->run($application);
