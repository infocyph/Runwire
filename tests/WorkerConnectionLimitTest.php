<?php

declare(strict_types=1);

use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Server;
use Infocyph\Runwire\StreamServer;
use Infocyph\Runwire\Protocol\RawCodec;

it('keeps the worker connection ceiling distinct from the listener ceiling', function (): void {
    $http = new Server(
        name: 'web',
        address: '127.0.0.1:0',
        handler: static function (): void {},
        listener: new ListenerOptions(maxConnections: 100),
    );
    $http = $http->withWorkerConnectionLimit(3);

    $stream = StreamServer::tcp(
        '127.0.0.1:0',
        static fn () => new RawCodec(),
        static function (): void {},
        'stream',
    )->withWorkerConnectionLimit(5);

    expect($http->listener->maxConnections)->toBe(100)
        ->and($http->workerConnectionLimit)->toBe(3)
        ->and($stream->workerConnectionLimit)->toBe(5);
});

it('rejects invalid worker connection ceilings', function (): void {
    expect(fn () => Server::http('127.0.0.1:0', static function (): void {})->withWorkerConnectionLimit(0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => StreamServer::tcp(
            '127.0.0.1:0',
            static fn () => new RawCodec(),
            static function (): void {},
        )->withWorkerConnectionLimit(1_000_001))->toThrow(InvalidArgumentException::class);
});
