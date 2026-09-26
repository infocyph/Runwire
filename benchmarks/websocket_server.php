<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\WebSocket\WebSocketMessage;
use Infocyph\Runwire\WebSocket\WebSocketOptions;
use Infocyph\Runwire\WebSocket\WebSocketSession;
use Infocyph\Runwire\WebSocket\WebSocketUpgrade;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 2) {
    throw new InvalidArgumentException('Usage: php websocket_server.php <port>');
}

$port = (int) $argv[1];
if ($port < 1 || $port > 65_535) {
    throw new InvalidArgumentException('Benchmark port must be between 1 and 65535.');
}

$options = new WebSocketOptions(
    maxFramePayloadBytes: 65_536,
    maxMessageBytes: 1_048_576,
    maxBufferedBytes: 1_048_576,
    maxReadBytesPerTurn: 262_144,
    maxFramesPerTurn: 128,
    heartbeatIntervalSeconds: 10.0,
    idleTimeoutSeconds: 30.0,
    closeTimeoutSeconds: 2.0,
);

$server = Server::http(
    '127.0.0.1:' . $port,
    static function (HttpRequest $request, ResponseWriterInterface $writer) use ($options): void {
        if ($request->target !== '/ws') {
            $writer->start(404);
            $writer->end();

            return;
        }

        $session = WebSocketUpgrade::accept($request, $writer, options: $options);
        if (!$session instanceof WebSocketSession) {
            return;
        }

        $session->onClose(
            static function (WebSocketSession $session, int $code, string $reason): void {
                fwrite(
                    STDERR,
                    sprintf(
                        'websocket-close closed=%s code=%d reason=%s' . PHP_EOL,
                        $session->isClosed() ? 'yes' : 'no',
                        $code,
                        $reason,
                    ),
                );
            },
        );

        $session->onMessage(
            static function (WebSocketSession $session, WebSocketMessage $message): void {
                try {
                    $result = $message->binary
                        ? $session->sendBinary($message->data)
                        : $session->sendText($message->data);
                    if (!$result->accepted()) {
                        $session->close(1011, 'echo write rejected');
                    }
                } catch (Throwable $error) {
                    fwrite(
                        STDERR,
                        sprintf(
                            'websocket-handler-error %s: %s' . PHP_EOL,
                            $error::class,
                            $error->getMessage(),
                        ),
                    );

                    throw $error;
                }
            },
        );
    },
    'websocket-benchmark',
)->withWorkers(1);

Runtime::create(new RuntimeOptions(driver: RuntimeDriver::NATIVE))
    ->listen($server)
    ->run();
