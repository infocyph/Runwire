<?php

declare(strict_types=1);

use Infocyph\Runwire\DatagramServer;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Http3\Http3Options;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Datagram;
use Infocyph\Runwire\Network\DatagramListener;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Protocol\FramedConnection;
use Infocyph\Runwire\Protocol\LineCodec;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\StreamServer;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\SupervisorEvent;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($argc !== 7) {
    throw new InvalidArgumentException(
        'Usage: mixed_native_runtime.php <tls-port> <tcp-port> <udp-port> <cert> <key> <event-log>',
    );
}

[$script, $tlsPort, $tcpPort, $udpPort, $certificate, $key, $eventLog] = $argv;
unset($script);
$http = new Server(
    name: 'mixed-http',
    address: '127.0.0.1:' . $tlsPort,
    handler: static function (HttpRequest $request, ResponseWriterInterface $writer): void {
        $size = 16_384;
        if (preg_match('~^/benchmark/(2|1024|16384)$~D', $request->target, $match) === 1) {
            $size = (int) $match[1];
        }
        $body = str_repeat('x', $size);
        $writer->start(200, Headers::fromArray([
            'content-length' => (string) $size,
            'content-type' => 'application/octet-stream',
            'x-upload-bytes' => '0',
        ]));
        $writer->end($body);
    },
    workers: 1,
    tls: new TlsOptions($certificate, $key),
    http3: new Http3Options(),
);

$tcp = StreamServer::tcp(
    '127.0.0.1:' . $tcpPort,
    static fn(): LineCodec => new LineCodec(),
    static function (string $frame, FramedConnection $connection): void {
        if ($frame === 'ping') {
            $connection->send('pong');
        }
    },
    'mixed-tcp',
)->withWorkers(1);

$udp = DatagramServer::udp(
    '127.0.0.1:' . $udpPort,
    static function (Datagram $datagram, DatagramListener $listener): void {
        if ($datagram->payload === 'ping') {
            $listener->sendTo('pong', $datagram->peerAddress);
        }
    },
    'mixed-udp',
)->withWorkers(1);

$runtime = Runtime::create(new RuntimeOptions(driver: RuntimeDriver::NATIVE))
    ->listen($http)
    ->listen($tcp)
    ->listen($udp);

$runtime->onEvent(static function (SupervisorEvent $event) use ($eventLog, $runtime): void {
    $record = json_encode([
        'type' => $event->type->value,
        'group' => $event->group,
        'pid' => $event->pid,
        'generation' => $event->generation,
        'time' => $event->atMonotonic,
    ], JSON_THROW_ON_ERROR);
    if (file_put_contents($eventLog, $record . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Could not persist native mixed listener lifecycle event.');
    }

    if ($event->type === SupervisorEventType::RELOAD_COMPLETED) {
        $accepted = $runtime->recycle('mixed-http', 0);
        $result = json_encode([
            'type' => 'manual_recycle_requested',
            'accepted' => $accepted,
            'time' => hrtime(true),
        ], JSON_THROW_ON_ERROR);
        file_put_contents($eventLog, $result . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
});
$runtime->run();
