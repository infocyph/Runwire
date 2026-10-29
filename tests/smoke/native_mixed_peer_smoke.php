<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Infocyph\\Runwire\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\Enum\SupervisorEventType;
use Infocyph\Runwire\Supervisor\RestartPolicy;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;

$http = stream_socket_server('tcp://127.0.0.1:0', $httpCode, $httpError);
$tcp = stream_socket_server('tcp://127.0.0.1:0', $tcpCode, $tcpError);
$udp = stream_socket_server('udp://127.0.0.1:0', $udpCode, $udpError, STREAM_SERVER_BIND);
if (!is_resource($http) || !is_resource($tcp) || !is_resource($udp)) {
    throw new RuntimeException('Unable to bind mixed-protocol native sockets.');
}

$httpAddress = stream_socket_get_name($http, false);
$tcpAddress = stream_socket_get_name($tcp, false);
$udpAddress = stream_socket_get_name($udp, false);
$loop = new SelectLoop();
$supervisor = new Supervisor($loop);
$supervisor->group(WorkerGroup::callbacks(
    name: 'mixed-native',
    count: 1,
    factory: static function (WorkerContext $context) use ($http, $tcp, $udp): void {
        foreach ([$http, $tcp, $udp] as $socket) {
            stream_set_blocking($socket, false);
        }
        $stop = $context->stopStream();
        $context->ready();

        while (!$context->stopping()) {
            $read = [$http, $tcp, $udp, $stop];
            $write = [];
            $except = [];
            $ready = stream_select($read, $write, $except, 0, 50_000);
            if ($ready === false) {
                throw new RuntimeException('Mixed-native worker poll failed.');
            }
            foreach ($read as $socket) {
                if ($socket === $stop) {
                    $context->consumeStopWake();
                } elseif ($socket === $udp) {
                    $remote = null;
                    if (stream_socket_recvfrom($udp, 1024, 0, $remote) === 'ping' && is_string($remote)) {
                        stream_socket_sendto($udp, 'pong', 0, $remote);
                    }
                } else {
                    $client = stream_socket_accept($socket, 0);
                    if (!is_resource($client)) {
                        continue;
                    }
                    stream_set_timeout($client, 1);
                    $incoming = fread($client, 2048);
                    if ($socket === $http && is_string($incoming) && str_starts_with($incoming, 'GET /live HTTP/1.1')) {
                        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
                    } elseif ($socket === $tcp && $incoming === 'ping') {
                        fwrite($client, 'pong');
                    }
                    fclose($client);
                }
            }
        }
    },
    restartPolicy: new RestartPolicy(maxRestarts: 4, windowSeconds: 8.0, initialBackoffSeconds: 0.001, maxBackoffSeconds: 0.02),
    shutdownTimeoutSeconds: 0.2,
));

$rounds = 0;
$pids = [];
$reloadCompleted = false;
$exchange = static function (string $address, string $payload): string {
    $peer = stream_socket_client('tcp://' . $address, $code, $message, 1.0);
    if (!is_resource($peer)) {
        throw new RuntimeException('TCP peer connection failed: ' . $message);
    }
    try {
        stream_set_timeout($peer, 1);
        fwrite($peer, $payload);
        $result = stream_get_contents($peer);

        return is_string($result) ? $result : '';
    } finally {
        fclose($peer);
    }
};

$supervisor->onEvent(static function ($event) use (
    $supervisor, $loop, $httpAddress, $tcpAddress, $udpAddress, $exchange, &$rounds, &$pids, &$reloadCompleted,
): void {
    if ($event->type === SupervisorEventType::RELOAD_COMPLETED) {
        $reloadCompleted = true;
        if ($rounds === 4) {
            $supervisor->stop();
        }

        return;
    }
    if ($event->type !== SupervisorEventType::WORKER_READY || $event->pid === null) {
        return;
    }
    $pids[] = $event->pid;
    $response = $exchange($httpAddress, "GET /live HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    if (!str_contains($response, '200 OK') || !str_ends_with($response, "\r\n\r\nok")
        || $exchange($tcpAddress, 'ping') !== 'pong') {
        throw new RuntimeException('HTTP/1 or TCP native response lost during lifecycle.');
    }
    $udpClient = stream_socket_client('udp://' . $udpAddress, $code, $error, 1.0);
    if (!is_resource($udpClient)) {
        throw new RuntimeException('UDP peer connection failed.');
    }
    try {
        stream_set_timeout($udpClient, 1);
        fwrite($udpClient, 'ping');
        if (fread($udpClient, 4) !== 'pong') {
            throw new RuntimeException('UDP native response lost during lifecycle.');
        }
    } finally {
        fclose($udpClient);
    }
    ++$rounds;
    if ($rounds === 1) {
        posix_kill($event->pid, SIGKILL);
    } elseif ($rounds === 2) {
        if (!$supervisor->recycle('mixed-native', 0)) {
            throw new RuntimeException('Native recycle unexpectedly refused.');
        }
    } elseif ($rounds === 3) {
        $loop->delay(0.05, static fn() => $supervisor->reload());
    } elseif ($rounds === 4 && $reloadCompleted) {
        $supervisor->stop();
    }
});

$loop->delay(7.0, static fn() => $supervisor->stop(true));
try {
    $supervisor->run();
    if ($rounds !== 4 || !$reloadCompleted
        || count(array_unique($pids)) !== 4 || $supervisor->status()->workerCount !== 0) {
        throw new RuntimeException('Mixed-peer crash, recycle, reload, or stop did not fully complete.');
    }
    fwrite(STDOUT, "Native live HTTP/1 + TCP + UDP across SIGKILL/recycle/reload/stop passed.\n");
} finally {
    fclose($http);
    fclose($tcp);
    fclose($udp);
}
