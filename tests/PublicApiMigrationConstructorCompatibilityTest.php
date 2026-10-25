<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http2\Internal\ResponseScheduler;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Worker;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\WebSocket\Internal\WebSocketFrameParser;
use Infocyph\Runwire\WebSocket\WebSocketSession;

it('preserves all six reviewed 2.1.2 constructor required-arity contracts', function (): void {
    $approved = [
        ResponseScheduler::class => [9, 10, 'outboundDataCredit', null],
        PhpQuicHttp3Worker::class => [4, 10, 'connectionFailureObserver', null],
        Connection::class => [2, 12, 'strictTlsPeerEof', false],
        TlsOptions::class => [1, 8, 'requireClientCertificate', false],
        WebSocketFrameParser::class => [1, 3, 'expectMasked', true],
        WebSocketSession::class => [2, 6, 'clientMode', false],
    ];
    foreach ($approved as $class => [$required, $total, $name, $default]) {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if (!$constructor instanceof ReflectionMethod) {
            throw new RuntimeException('Missing reviewed public constructor: ' . $class);
        }
        $parameters = $constructor->getParameters();
        $last = $parameters[count($parameters) - 1];
        expect($constructor->isPublic())->toBeTrue($class)
            ->and($constructor->getNumberOfRequiredParameters())->toBe($required, $class)
            ->and($constructor->getNumberOfParameters())->toBe($total, $class)
            ->and($last->getName())->toBe($name, $class)
            ->and($last->isOptional())->toBeTrue($class)
            ->and($last->isDefaultValueAvailable())->toBeTrue($class)
            ->and($last->getDefaultValue())->toBe($default, $class);
    }

    // The one constructor with two appended options must preserve both
    // original TLS identity and backward-compatible non-strict EOF defaults.
    $parameters = (new ReflectionMethod(Connection::class, '__construct'))->getParameters();
    $fingerprint = $parameters[count($parameters) - 2];
    expect($fingerprint->getName())->toBe('tlsPeerFingerprint')
        ->and($fingerprint->isOptional())->toBeTrue()
        ->and($fingerprint->getDefaultValue())->toBeNull();
});
