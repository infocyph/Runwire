<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Network\AsyncDialer;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SwooleLoop;
use Infocyph\Runwire\Network\AsyncDnsResolver;
use Infocyph\Runwire\Network\Dialer;
use Infocyph\Runwire\Network\HappyEyeballsDialer;
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
        if ($request->target === '/dial') {
            $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            if (!is_resource($listener)) {
                throw new RuntimeException($error);
            }
            $executor = new ProcessExecutor($loop, 1, 4);
            try {
                $address = stream_socket_get_name($listener, false);
                if (!is_string($address)) {
                    throw new RuntimeException('Hosted dial test could not resolve a listener address.');
                }
                $port = (int) substr($address, strrpos($address, ':') + 1);
                $dialer = new HappyEyeballsDialer($loop, new Dialer($loop), new AsyncDnsResolver($executor));
                $connection = (new AsyncDialer($dialer, $scope))->connect('tcp://localhost:' . $port);
                $connection->abort();
                $peer = stream_socket_accept($listener, 1.0);
                if (!is_resource($peer)) {
                    throw new RuntimeException('Hosted dial test did not accept the outgoing socket.');
                }
                fclose($peer);
                $executor->close();
                $writer->start(200, Headers::fromArray(['content-type' => 'text/plain']));
                $writer->end('runwire-swoole-dial-ok');
            } finally {
                $executor->close();
                fclose($listener);
            }

            return;
        }

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
