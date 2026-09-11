<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;

it('rejects duplicate server names before runtime start', function (): void {
    $runtime = Runtime::create(new RuntimeOptions(RuntimeDriver::NATIVE));
    $server = Server::http('127.0.0.1:1', static function (): void {});
    $runtime->listen($server);

    expect(fn () => $runtime->listen($server))->toThrow(LogicException::class);
});

it('serves through a prefork native worker and shuts down cleanly', function (): void {
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect($probe)->toBeResource();
    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    expect($address)->toBeString();

    $runtimePid = pcntl_fork();
    expect($runtimePid)->toBeGreaterThanOrEqual(0);
    if ($runtimePid === 0) {
        $server = Server::httpFactory($address, static function ($context): callable {
            return static function (HttpRequest $request, ResponseWriterInterface $writer): void {
                $request->body->onEnd(static fn () => $writer->end('runwire-native-ok'));
            };
        })->withWorkers(1);

        Runtime::create(new RuntimeOptions(RuntimeDriver::NATIVE))->listen($server)->run();
        exit(0);
    }

    $client = false;
    try {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $client = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
            if (is_resource($client)) {
                break;
            }
            usleep(20_000);
        }
        expect($client)->toBeResource();

        fwrite($client, "GET / HTTP/1.1\r\nHost: example.test\r\n\r\n");
        stream_set_timeout($client, 2);
        $response = '';
        while (!feof($client) && !str_contains($response, 'runwire-native-ok')) {
            $chunk = fread($client, 8_192);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }
        expect($response)->toContain('HTTP/1.1 200')->toContain('runwire-native-ok');
    } finally {
        if (is_resource($client)) {
            fclose($client);
        }
        @posix_kill($runtimePid, SIGTERM);
    }

    $status = 0;
    $reaped = 0;
    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        $reaped = pcntl_waitpid($runtimePid, $status, WNOHANG);
        if ($reaped === $runtimePid) {
            break;
        }
        usleep(20_000);
    }
    if ($reaped !== $runtimePid) {
        @posix_kill($runtimePid, SIGKILL);
        pcntl_waitpid($runtimePid, $status);
    }

    expect($reaped)->toBe($runtimePid)
        ->and(pcntl_wifexited($status))->toBeTrue()
        ->and(pcntl_wexitstatus($status))->toBe(0);
});
