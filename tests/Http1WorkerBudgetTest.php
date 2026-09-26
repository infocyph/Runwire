<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http1\Http1Connection;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\StreamingRequestBody;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\LoopFactory;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Metrics\DiagnosticsPolicy;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\Internal\ByteBudget;

/** @return array{0: resource, 1: resource} */
function http1BudgetPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!is_array($pair)) {
        throw new RuntimeException('Unable to create HTTP/1 worker-budget socket pair.');
    }

    return $pair;
}

/**
 * @return array{
 *   budget: ByteBudget,
 *   bodies: list<StreamingRequestBody>,
 *   connections: list<Connection>,
 *   clients: list<resource>
 * }
 */
function http1BudgetRetainedBodies(LoopInterface $loop): array
{
    $budget = new ByteBudget(1_024);
    $bodies = [];
    $connections = [];
    $clients = [];
    $connectionLimits = new ConnectionLimits(
        readChunkBytes: 1_024,
        maxReadBytesPerTick: 2_048,
        receiveLowWatermarkBytes: 128,
        receiveHighWatermarkBytes: 768,
        maxReceiveBufferBytes: 1_024,
        sendLowWatermarkBytes: 128,
        sendHighWatermarkBytes: 512,
        maxSendBufferBytes: 1_024,
        maxWriteBytesPerTick: 1_024,
    );
    $httpLimits = new Http1Limits(
        maxBodyBytes: 4_096,
        bodyLowWatermarkBytes: 128,
        bodyHighWatermarkBytes: 900,
        maxPendingBodyBytes: 1_024,
        bodyIdleTimeoutSeconds: 1.0,
    );

    for ($index = 0; $index < 2; ++$index) {
        [$server, $client] = http1BudgetPair();
        $connection = new Connection(
            $loop,
            $server,
            $connectionLimits,
            bufferBudget: $budget,
        );
        new Http1Connection(
            $loop,
            $connection,
            $httpLimits,
            static function (HttpRequest $request) use (&$bodies): void {
                if (!$request->body instanceof StreamingRequestBody) {
                    throw new RuntimeException('Expected streaming HTTP/1 request body.');
                }

                $bodies[] = $request->body;
            },
        );
        $connections[] = $connection;
        $clients[] = $client;
    }

    fwrite(
        $clients[0],
        "POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 700\r\n\r\n"
        . str_repeat('a', 600),
    );
    $loop->delay(0.005, static function () use ($clients): void {
        fwrite(
            $clients[1],
            "POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 700\r\n\r\n"
            . str_repeat('b', 600),
        );
    });
    $loop->delay(0.04, static function () use ($loop): void {
        $loop->stop();
    });
    $loop->run();

    return [
        'budget' => $budget,
        'bodies' => $bodies,
        'connections' => $connections,
        'clients' => $clients,
    ];
}

function assertHttp1RetainedBodiesRespectBudget(LoopInterface $loop): void
{
    $scenario = http1BudgetRetainedBodies($loop);
    /** @var ByteBudget $budget */
    $budget = $scenario['budget'];
    /** @var list<StreamingRequestBody> $bodies */
    $bodies = $scenario['bodies'];
    /** @var list<Connection> $connections */
    $connections = $scenario['connections'];
    /** @var list<resource> $clients */
    $clients = $scenario['clients'];

    try {
        expect($bodies)->toHaveCount(2);

        $bodyBytes = array_sum(array_map(
            static fn(StreamingRequestBody $body): int => $body->bufferedBytes(),
            $bodies,
        ));

        expect($bodyBytes)->toBeGreaterThan(0)
            ->and($bodyBytes)->toBeLessThanOrEqual(1_024)
            ->and($budget->used())->toBeGreaterThanOrEqual($bodyBytes)
            ->and($budget->used())->toBeLessThanOrEqual(1_024);
    } finally {
        foreach ($bodies as $body) {
            $body->discardBuffered();
        }
        foreach ($connections as $connection) {
            $connection->abort();
        }
        foreach ($clients as $client) {
            if (is_resource($client)) {
                fclose($client);
            }
        }
    }

    expect($budget->used())->toBe(0);
}

it('charges simultaneous retained HTTP1 bodies to the shared worker budget on SelectLoop', function (): void {
    assertHttp1RetainedBodiesRespectBudget(new SelectLoop());
});

it('charges simultaneous retained HTTP1 bodies on the selected native loop backend', function (): void {
    assertHttp1RetainedBodiesRespectBudget(LoopFactory::native(new DiagnosticsPolicy()));
});

it('charges partial HTTP1 header lines and releases them on abort', function (): void {
    [$server, $client] = http1BudgetPair();
    $loop = new SelectLoop();
    $budget = new ByteBudget(256);
    $connection = new Connection(
        $loop,
        $server,
        new ConnectionLimits(
            readChunkBytes: 256,
            maxReadBytesPerTick: 256,
            receiveLowWatermarkBytes: 64,
            receiveHighWatermarkBytes: 192,
            maxReceiveBufferBytes: 256,
        ),
        bufferBudget: $budget,
    );
    new Http1Connection(
        $loop,
        $connection,
        new Http1Limits(headerTimeoutSeconds: 1.0),
        static function (): void {
            throw new RuntimeException('Partial headers must not dispatch.');
        },
    );

    fwrite($client, "GET / HTTP/1.1\r\nX-Partial: " . str_repeat('x', 120));
    $loop->delay(0.02, static function () use ($loop): void {
        $loop->stop();
    });
    $loop->run();

    expect($budget->used())->toBeGreaterThan(0)
        ->and($budget->used())->toBeLessThanOrEqual(256);

    $connection->abort();
    fclose($client);

    expect($budget->used())->toBe(0);
});

it('releases shared HTTP1 budget after body consumption and normal close', function (): void {
    [$server, $client] = http1BudgetPair();
    $loop = new SelectLoop();
    $budget = new ByteBudget(2_048);
    $connection = new Connection($loop, $server, bufferBudget: $budget);
    $received = '';

    new Http1Connection(
        $loop,
        $connection,
        new Http1Limits(maxBodyBytes: 2_048, maxPendingBodyBytes: 1_024),
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$received): void {
            $request->body->onData(static function ($body) use (&$received): void {
                $received .= $body->read();
            });
            $request->body->onEnd(static fn() => $writer->end('ok'));
        },
    );

    $payload = str_repeat('r', 900);
    fwrite(
        $client,
        "POST /read HTTP/1.1\r\nHost: x\r\nContent-Length: 900\r\nConnection: close\r\n\r\n"
        . $payload,
    );
    $loop->delay(0.2, static function () use ($loop): void {
        $loop->stop();
    });
    $loop->run();
    fclose($client);

    expect($received)->toBe($payload)
        ->and($budget->used())->toBe(0);
});

it('releases shared HTTP1 budget on early response discard and premature EOF', function (): void {
    [$earlyServer, $earlyClient] = http1BudgetPair();
    $earlyLoop = new SelectLoop();
    $earlyBudget = new ByteBudget(2_048);
    $earlyConnection = new Connection($earlyLoop, $earlyServer, bufferBudget: $earlyBudget);
    new Http1Connection(
        $earlyLoop,
        $earlyConnection,
        new Http1Limits(maxBodyBytes: 2_048, maxPendingBodyBytes: 1_024),
        static function (HttpRequest $request, ResponseWriterInterface $writer): void {
            unset($request);
            $writer->end('early');
        },
    );
    fwrite(
        $earlyClient,
        "POST /early HTTP/1.1\r\nHost: x\r\nContent-Length: 1200\r\nConnection: close\r\n\r\n"
        . str_repeat('e', 1_200),
    );
    $earlyLoop->delay(0.2, static function () use ($earlyLoop): void {
        $earlyLoop->stop();
    });
    $earlyLoop->run();
    fclose($earlyClient);

    expect($earlyBudget->used())->toBe(0);

    [$eofServer, $eofClient] = http1BudgetPair();
    $eofLoop = new SelectLoop();
    $eofBudget = new ByteBudget(1_024);
    $eofConnection = new Connection($eofLoop, $eofServer, bufferBudget: $eofBudget);
    new Http1Connection(
        $eofLoop,
        $eofConnection,
        new Http1Limits(maxBodyBytes: 2_048, maxPendingBodyBytes: 1_024),
        static function (): void {},
    );
    fwrite(
        $eofClient,
        "POST /short HTTP/1.1\r\nHost: x\r\nContent-Length: 700\r\n\r\n"
        . str_repeat('s', 300),
    );
    stream_socket_shutdown($eofClient, STREAM_SHUT_WR);
    $eofLoop->delay(0.2, static function () use ($eofLoop): void {
        $eofLoop->stop();
    });
    $eofLoop->run();
    fclose($eofClient);

    expect($eofBudget->used())->toBe(0);
});
