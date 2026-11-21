<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\Network\Internal\StreamWriter;
use ReflectionMethod;
use RuntimeException;
use TypeError;

it('contains expected broken-pipe fwrite notices without leaking them to the caller', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if (!is_array($pair)) {
        throw new RuntimeException('Unable to create test socket pair.');
    }

    [$stream, $peer] = $pair;
    $reported = [];
    set_error_handler(static function (int $severity, string $message) use (&$reported): bool {
        $reported[] = [$severity, $message];

        return true;
    });

    try {
        fclose($peer);

        expect(StreamWriter::write($stream, 'disconnected peer'))->toBeFalse();

        trigger_error('outer error handler still active', E_USER_NOTICE);
        expect($reported)->toBe([[E_USER_NOTICE, 'outer error handler still active']]);
    } finally {
        restore_error_handler();
        fclose($stream);
    }
});

it('restores the previous error handler after a failed fwrite invocation', function (): void {
    $reported = [];
    set_error_handler(static function (int $severity, string $message) use (&$reported): bool {
        $reported[] = [$severity, $message];

        return true;
    });

    try {
        expect(static fn(): mixed => (new ReflectionMethod(StreamWriter::class, 'write'))->invoke(null, null, 'invalid resource'))
            ->toThrow(TypeError::class);

        trigger_error('error handler restored after exception', E_USER_NOTICE);
        expect($reported)->toBe([[E_USER_NOTICE, 'error handler restored after exception']]);
    } finally {
        restore_error_handler();
    }
});

it('only recognizes fwrite warnings and notices as expected transport write diagnostics', function (): void {
    $handler = new ReflectionMethod(StreamWriter::class, 'writeWarning');

    expect($handler->invoke(null, E_WARNING, 'fwrite(): failed'))->toBeTrue()
        ->and($handler->invoke(null, E_NOTICE, 'fwrite(): Send of 4 bytes failed with errno=32 Broken pipe'))->toBeTrue()
        ->and($handler->invoke(null, E_NOTICE, 'an unrelated notice'))->toBeFalse()
        ->and($handler->invoke(null, E_WARNING, 'other stream error'))->toBeFalse()
        ->and($handler->invoke(null, E_USER_NOTICE, 'fwrite(): unrelated user notice'))->toBeFalse();
});
