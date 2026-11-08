<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\Internal\VerifiedTargetTlsUpgrade;
use Infocyph\Runwire\Network\TlsClientOptions;
use LogicException;
use Throwable;


it('fails a closed post-tunnel TLS stream without starting an asynchronous handshake', function (): void {
    $stream = fopen('php://temp', 'w+');
    expect($stream)->toBeResource();
    fclose($stream);

    $calls = 0;
    $connection = null;
    $error = null;

    VerifiedTargetTlsUpgrade::start(
        new SelectLoop(),
        $stream,
        new TlsClientOptions(peerName: 'example.com'),
        new ConnectionLimits(),
        false,
        null,
        static function (?Connection $result, ?Throwable $failure) use (&$calls, &$connection, &$error): void {
            ++$calls;
            $connection = $result;
            $error = $failure;
        },
    );

    expect($calls)->toBe(1)
        ->and($connection)->toBeNull()
        ->and($error)->toBeInstanceOf(LogicException::class);
});
