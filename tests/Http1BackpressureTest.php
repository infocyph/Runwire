<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http1\Http1Connection;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;

function runwireHttpBackpressurePair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->toBeArray();

    return $pair;
}

it('pauses request intake at the body watermark and resumes after consumption', function (): void {
    [$server, $client] = runwireHttpBackpressurePair();
    $loop = new SelectLoop();
    $connection = new Connection($loop, $server);
    $limits = new Http1Limits(
        maxBodyBytes: 4_096,
        bodyLowWatermarkBytes: 64,
        bodyHighWatermarkBytes: 128,
        maxPendingBodyBytes: 256,
        bodyIdleTimeoutSeconds: 1.0,
    );
    $seen = '';

    new Http1Connection($loop, $connection, $limits, static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$seen, $loop): void {
        $loop->delay(0.02, static function () use ($request, &$seen, $writer): void {
            $request->body->onData(static function ($body) use (&$seen): void { $seen .= $body->read(); });
            $request->body->onEnd(static fn () => $writer->end('ok'));
        });
    });

    $payload = str_repeat('b', 1_024);
    fwrite($client, "POST /pressure HTTP/1.1\r\nHost: x\r\nContent-Length: 1024\r\nConnection: close\r\n\r\n" . $payload);
    $loop->delay(1.0, static fn () => $loop->stop());
    $loop->run();
    fclose($client);

    expect($seen)->toBe($payload);
});

it('applies absolute header and body-idle deadlines', function (): void {
    [$headerServer, $headerClient] = runwireHttpBackpressurePair();
    $headerLoop = new SelectLoop();
    $headerConnection = new Connection($headerLoop, $headerServer);
    new Http1Connection($headerLoop, $headerConnection, new Http1Limits(headerTimeoutSeconds: 0.03), static function (): void {});
    fwrite($headerClient, 'GET /slow HTTP/1.1');
    $headerLoop->delay(0.08, static fn () => $headerLoop->stop());
    $headerLoop->run();
    stream_set_blocking($headerClient, false);
    $headerResponse = stream_get_contents($headerClient);
    fclose($headerClient);

    [$bodyServer, $bodyClient] = runwireHttpBackpressurePair();
    $bodyLoop = new SelectLoop();
    $bodyConnection = new Connection($bodyLoop, $bodyServer);
    new Http1Connection($bodyLoop, $bodyConnection, new Http1Limits(bodyIdleTimeoutSeconds: 0.03), static function (HttpRequest $request): void {
        $request->body->onData(static fn ($body) => $body->read());
    });
    fwrite($bodyClient, "POST /slow HTTP/1.1\r\nHost: x\r\nContent-Length: 10\r\n\r\nabc");
    $bodyLoop->delay(0.08, static fn () => $bodyLoop->stop());
    $bodyLoop->run();
    stream_set_blocking($bodyClient, false);
    $bodyResponse = stream_get_contents($bodyClient);
    fclose($bodyClient);

    expect($headerResponse)->toStartWith('HTTP/1.1 408 Request Timeout')
        ->and($bodyResponse)->toStartWith('HTTP/1.1 408 Request Timeout');
});

it('exposes response pressure relief through the version-neutral writer', function (): void {
    [$server, $client] = runwireHttpBackpressurePair();
    stream_set_blocking($client, false);
    $loop = new SelectLoop();
    $connection = new Connection($loop, $server, new ConnectionLimits(
        readChunkBytes: 1_024,
        maxReadBytesPerTick: 4_096,
        receiveLowWatermarkBytes: 1_024,
        receiveHighWatermarkBytes: 2_048,
        maxReceiveBufferBytes: 4_096,
        sendLowWatermarkBytes: 4_096,
        sendHighWatermarkBytes: 8_192,
        maxSendBufferBytes: 65_536,
        maxWriteBytesPerTick: 1,
    ));
    $drained = false;

    new Http1Connection($loop, $connection, new Http1Limits(maxResponseChunkBytes: 4_096), static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$drained, $loop): void {
        expect($request->target)->toBe('/pressure');
        $writer->start();
        for ($index = 0; $index < 20; ++$index) {
            $result = $writer->write(str_repeat('z', 4_096));
            if ($result->pressured()) {
                $writer->onDrain(static function () use (&$drained, $loop): void {
                    $drained = true;
                    $loop->stop();
                });
                return;
            }
            if (!$result->accepted()) {
                return;
            }
        }
    });

    fwrite($client, "GET /pressure HTTP/1.1\r\nHost: x\r\n\r\n");
    $loop->onReadable($client, static function ($stream): void {
        do {
            $chunk = fread($stream, 65_536);
        } while ($chunk !== '' && $chunk !== false);
    });
    $loop->delay(1.0, static fn () => $loop->stop());
    $loop->run();
    $connection->abort();
    fclose($client);

    expect($drained)->toBeTrue();
});
