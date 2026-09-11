<?php

declare(strict_types=1);

use Infocyph\Runwire\DatagramServer;
use Infocyph\Runwire\Network\Datagram;
use Infocyph\Runwire\Network\DatagramListener;
use Infocyph\Runwire\Protocol\FramedConnection;
use Infocyph\Runwire\Protocol\LineCodec;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\StreamServer;

it('serves framed TCP and UDP workloads through prefork native workers', function (): void {
    $tcpProbe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!is_resource($tcpProbe)) {
        throw new RuntimeException(sprintf('Unable to reserve TCP port: %s (%d).', $error, $errno));
    }
    $tcpAddress = stream_socket_get_name($tcpProbe, false);
    fclose($tcpProbe);
    if (!is_string($tcpAddress)) {
        throw new RuntimeException('Unable to determine reserved TCP address.');
    }

    $udpProbe = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
    if (!is_resource($udpProbe)) {
        throw new RuntimeException(sprintf('Unable to reserve UDP port: %s (%d).', $error, $errno));
    }
    $udpAddress = stream_socket_get_name($udpProbe, false);
    fclose($udpProbe);
    if (!is_string($udpAddress)) {
        throw new RuntimeException('Unable to determine reserved UDP address.');
    }

    $pid = pcntl_fork();
    if ($pid < 0) {
        throw new RuntimeException('Unable to fork generic runtime test.');
    }
    if ($pid === 0) {
        $runtime = Runtime::create(new RuntimeOptions(RuntimeDriver::NATIVE));
        $runtime->listen(StreamServer::tcp(
            $tcpAddress,
            static fn () => new LineCodec(),
            static function (string $frame, FramedConnection $connection): void {
                $connection->send('stream:' . $frame);
            },
            'line',
        ));
        $runtime->listen(DatagramServer::udp(
            $udpAddress,
            static function (Datagram $datagram, DatagramListener $listener): void {
                $listener->sendTo('udp:' . $datagram->payload, $datagram->peerAddress);
            },
            'dgram',
        ));
        $runtime->run();
        exit(0);
    }

    $tcp = false;
    $udp = false;
    try {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $tcp = stream_socket_client('tcp://' . $tcpAddress, $errno, $error, 0.05);
            if (is_resource($tcp)) {
                break;
            }
            usleep(20_000);
        }
        if (!is_resource($tcp)) {
            throw new RuntimeException('TCP generic runtime worker did not become reachable.');
        }
        fwrite($tcp, "hello\n");
        stream_set_timeout($tcp, 2);
        expect(fgets($tcp))->toBe("stream:hello\n");

        $udp = stream_socket_client('udp://' . $udpAddress, $errno, $error, 1.0);
        if (!is_resource($udp)) {
            throw new RuntimeException(sprintf('Unable to create UDP runtime client: %s (%d).', $error, $errno));
        }
        fwrite($udp, 'hello');
        stream_set_timeout($udp, 2);
        expect(fread($udp, 9))->toBe('udp:hello');
    } finally {
        if (is_resource($tcp)) {
            fclose($tcp);
        }
        if (is_resource($udp)) {
            fclose($udp);
        }
        posix_kill($pid, SIGTERM);
    }

    $status = 0;
    $reaped = 0;
    $deadline = microtime(true) + 4.0;
    while (microtime(true) < $deadline) {
        $reaped = pcntl_waitpid($pid, $status, WNOHANG);
        if ($reaped === $pid) {
            break;
        }
        usleep(20_000);
    }
    if ($reaped !== $pid) {
        posix_kill($pid, SIGKILL);
        $reaped = pcntl_waitpid($pid, $status);
    }

    expect($reaped)->toBe($pid)
        ->and(pcntl_wifexited($status))->toBeTrue()
        ->and(pcntl_wexitstatus($status))->toBe(0);
});

it('keeps a prefork Unix socket path master-owned until runtime shutdown', function (): void {
    $path = sys_get_temp_dir() . '/runwire-runtime-' . bin2hex(random_bytes(6)) . '.sock';
    $pid = pcntl_fork();
    if ($pid < 0) {
        throw new RuntimeException('Unable to fork Unix runtime test.');
    }
    if ($pid === 0) {
        Runtime::create(new RuntimeOptions(RuntimeDriver::NATIVE))
            ->listen(StreamServer::unix(
                $path,
                static fn () => new LineCodec(),
                static function (string $frame, FramedConnection $connection): void {
                    $connection->send('unix:' . $frame);
                },
                'unix-line',
            )->withWorkers(2))
            ->run();
        exit(0);
    }

    $client = false;
    try {
        for ($attempt = 0; $attempt < 150; ++$attempt) {
            if (file_exists($path)) {
                $client = stream_socket_client('unix://' . $path, $errno, $error, 0.05);
                if (is_resource($client)) {
                    break;
                }
            }
            usleep(20_000);
        }
        if (!is_resource($client)) {
            throw new RuntimeException('Unix runtime worker did not become reachable.');
        }
        fwrite($client, "hello\n");
        stream_set_timeout($client, 2);
        expect(fgets($client))->toBe("unix:hello\n")->and(file_exists($path))->toBeTrue();
    } finally {
        if (is_resource($client)) {
            fclose($client);
        }
        posix_kill($pid, SIGTERM);
    }

    $status = 0;
    $reaped = 0;
    $deadline = microtime(true) + 4.0;
    while (microtime(true) < $deadline) {
        $reaped = pcntl_waitpid($pid, $status, WNOHANG);
        if ($reaped === $pid) {
            break;
        }
        usleep(20_000);
    }
    if ($reaped !== $pid) {
        posix_kill($pid, SIGKILL);
        $reaped = pcntl_waitpid($pid, $status);
    }

    expect($reaped)->toBe($pid)->and(file_exists($path))->toBeFalse();
});
