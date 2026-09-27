<?php

declare(strict_types=1);

$root = getenv('PEER_ROOT');
$port = (int) (getenv('PEER_PORT') ?: 0);
$tcpNoDelay = getenv('PEER_NODELAY') === '1';

if (!is_string($root) || $root === '' || $port < 1 || $port > 65_535) {
    throw new InvalidArgumentException('PEER_ROOT and PEER_PORT are required.');
}

require $root . '/vendor/autoload.php';

use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\BindContext;
use Psr\Log\NullLogger;

$logger = new NullLogger();
$server = SocketHttpServer::createForDirectAccess(
    logger: $logger,
    enableCompression: false,
    concurrencyLimit: null,
    allowedMethods: null,
);

$context = new BindContext();
if ($tcpNoDelay) {
    $context = $context->withTcpNoDelay();
}
$server->expose('127.0.0.1:' . $port, $context);

$handler = new class implements RequestHandler {
    public function handleRequest(Request $request): Response
    {
        return new Response(
            status: HttpStatus::OK,
            headers: [
                'Content-Type' => 'text/plain',
                'Content-Length' => '2',
            ],
            body: 'xx',
        );
    }
};

$server->start($handler, new DefaultErrorHandler());
\Amp\trapSignal([SIGINT, SIGTERM]);
$server->stop();
