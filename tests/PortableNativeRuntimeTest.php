<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Internal\BoundServer;
use Infocyph\Runwire\Runtime\Internal\PortableNativeRuntime;
use Infocyph\Runwire\Runtime\RuntimeCapabilityResolver;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\Runtime\RuntimeSelection;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\Supervisor\WorkerContext;

it('supports worker lifecycle bookkeeping without a supervisor signal channel', function (): void {
    $context = new WorkerContext(
        group: 'portable:test',
        slot: 0,
        generation: 0,
        pid: 1,
        parentPid: 1,
    );

    try {
        $context->ready();
        $context->recordRequestStarted();
        expect($context->recordRequestCompleted())->toBeFalse();
        $context->requestStop();
        $context->consumeStopWake();

        expect($context->stopping())->toBeTrue()
            ->and($context->recycling())->toBeFalse()
            ->and($context->requestsTotal())->toBe(1);
    } finally {
        $context->close();
    }
});

it('serves HTTP through the single-process native fallback without prefork capabilities', function (): void {
    $portable = null;
    $server = Server::http(
        '127.0.0.1:0',
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$portable): void {
            expect($request->target)->toBe('/portable');
            $writer->end('runwire-portable-ok');
            $portable?->stop();
        },
    )->withWorkers(1);
    $listener = TcpListener::bind(
        $server->address,
        $server->listener,
        $server->connection,
        $server->tls,
    );
    $environment = new RuntimeEnvironment(
        sapi: 'cli',
        availableDrivers: [RuntimeDriver::NATIVE],
    );
    $capabilities = (new RuntimeCapabilityResolver())->resolve(RuntimeDriver::NATIVE, $environment);
    $selection = new RuntimeSelection(RuntimeDriver::NATIVE, $capabilities);
    $portable = new PortableNativeRuntime(
        ['web' => new BoundServer($server, $listener)],
        $selection,
        new RuntimeOptions(driver: RuntimeDriver::NATIVE),
    );

    $errno = 0;
    $error = '';
    $client = stream_socket_client(
        'tcp://' . $listener->address(),
        $errno,
        $error,
        1.0,
    );
    if (!is_resource($client)) {
        throw new RuntimeException(sprintf('Unable to connect portable runtime test client: %s (%d).', $error, $errno));
    }

    try {
        stream_set_timeout($client, 2);
        fwrite(
            $client,
            "GET /portable HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n",
        );

        $portable->run();
        $response = stream_get_contents($client);

        expect($capabilities->ownsWorkerPool)->toBeFalse()
            ->and($capabilities->supportsGracefulReload)->toBeFalse()
            ->and($response)->toContain('HTTP/1.1 200')
            ->and($response)->toContain('runwire-portable-ok');
    } finally {
        fclose($client);
        $listener->close();
    }
});
