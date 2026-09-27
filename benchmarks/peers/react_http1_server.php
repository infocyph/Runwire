<?php

declare(strict_types=1);

$root = getenv('PEER_ROOT');
$port = (int) (getenv('PEER_PORT') ?: 0);
$tcpNoDelay = getenv('PEER_NODELAY') === '1';

if (!is_string($root) || $root === '' || $port < 1 || $port > 65_535) {
    throw new InvalidArgumentException('PEER_ROOT and PEER_PORT are required.');
}

require $root . '/vendor/autoload.php';

use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;

$loop = Loop::get();
$socket = new SocketServer(
    '127.0.0.1:' . $port,
    $tcpNoDelay ? ['tcp_nodelay' => true] : [],
    $loop,
);
$server = new HttpServer(static fn (): Response => new Response(
    200,
    ['Content-Type' => 'text/plain'],
    'xx',
));
$server->listen($socket);
$loop->run();
