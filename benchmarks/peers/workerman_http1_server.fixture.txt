<?php

declare(strict_types=1);

$root = getenv('PEER_ROOT');
$port = (int) (getenv('PEER_PORT') ?: 0);

if (!is_string($root) || $root === '' || $port < 1 || $port > 65_535) {
    throw new InvalidArgumentException('PEER_ROOT and PEER_PORT are required.');
}

require $root . '/vendor/autoload.php';

use Workerman\Connection\TcpConnection;
use Workerman\Worker;

Worker::$stdoutFile = '/dev/null';
Worker::$logFile = '/dev/null';
Worker::$pidFile = sys_get_temp_dir() . '/runwire-peer-workerman-' . $port . '.pid';
Worker::$statusFile = sys_get_temp_dir() . '/runwire-peer-workerman-' . $port . '.status';

$worker = new Worker('http://127.0.0.1:' . $port);
$worker->name = 'runwire-peer-workerman';
$worker->count = 1;
$worker->onMessage = static function (TcpConnection $connection): void {
    $connection->send('xx');
};

Worker::runAll();
