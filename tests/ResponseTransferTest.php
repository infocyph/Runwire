<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\ResponseTransfer;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;


function responseTransferWriter(LoopInterface $loop, int $pressureEvery = 0): \Infocyph\Runwire\Tests\Fixtures\TransferWriter
{
    return new \Infocyph\Runwire\Tests\Fixtures\TransferWriter($loop, $pressureEvery);
}

/**
 * @return resource
 * @throws \PHPUnit\Framework\Exception
  * @throws \PHPUnit\Framework\ExpectationFailedException
 */
function responseTransferSource(string $payload): mixed
{
    $source = fopen('php://temp', 'w+b');
    \PHPUnit\Framework\Assert::assertIsResource($source);
    fwrite($source, $payload);
    rewind($source);

    return $source;
}

it('transfers bounded chunks with backpressure and cooperative fairness', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $writer = responseTransferWriter($loop, 3);
    $payload = str_repeat('runwire-transfer-', 8_192);
    $source = responseTransferSource($payload);
    $ticks = 0;

    $transferred = $runtime->run(static function (CoroutineScope $scope) use ($source, $writer, &$ticks): int {
        $scope->spawn(static function () use ($scope, &$ticks): void {
            for ($index = 0; $index < 8; ++$index) {
                ++$ticks;
                $scope->yieldNow();
            }
        });

        return ResponseTransfer::stream(
            $scope,
            $source,
            $writer,
            chunkBytes: 4_096,
            chunksPerTurn: 2,
        );
    });

    expect($transferred)->toBe(strlen($payload))
        ->and($writer->body)->toBe($payload)
        ->and($writer->isEnded())->toBeTrue()
        ->and($ticks)->toBeGreaterThan(0)
        ->and(get_resource_type($source))->toBe('Unknown');
});

it('cancels a blocked transfer and closes its source', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $context = RequestContext::standalone();
    [$source, $peer] = runwireResponseTransferTestSocketPair();
    \PHPUnit\Framework\Assert::assertSame('stream', get_resource_type($source));
    \PHPUnit\Framework\Assert::assertSame('stream', get_resource_type($peer));
    $writer = responseTransferWriter($loop);

    $loop->delay(0.01, static function () use ($context): void {
        $context->cancel(CancellationReason::HOST_CANCELLED);
    });

    expect(static function () use ($runtime, $context, $source, $writer): void {
        $runtime->runRequest(
            $context,
            static fn(CoroutineScope $scope): int => ResponseTransfer::stream($scope, $source, $writer),
        );
    })->toThrow(CancelledException::class);

    expect(get_resource_type($source))->toBe('Unknown')
        ->and($writer->isEnded())->toBeFalse();

    fclose($peer);
});

it('can preserve caller source ownership and blocking mode', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $writer = responseTransferWriter($loop);
    $source = tmpfile();
    \PHPUnit\Framework\Assert::assertIsResource($source);
    expect(fwrite($source, 'preserved-source'))->toBe(16);
    expect(rewind($source))->toBeTrue();
    expect(stream_set_blocking($source, true))->toBeTrue();
    $before = stream_get_meta_data($source);

    $transferred = $runtime->run(
        static fn(CoroutineScope $scope): int => ResponseTransfer::stream(
            $scope,
            $source,
            $writer,
            chunkBytes: 4,
            chunksPerTurn: 1,
            closeSource: false,
        ),
    );
    $after = stream_get_meta_data($source);

    expect($transferred)->toBe(16)
        ->and($writer->body)->toBe('preserved-source')
        ->and(get_resource_type($source))->not->toBe('Unknown')
        ->and($after['blocked'])->toBe($before['blocked']);

    fclose($source);
});

it('rejects invalid transfer sources and unsafe chunk policies', function (): void {
    $loop = new SelectLoop();
    $runtime = new CoroutineRuntime($loop);
    $writer = responseTransferWriter($loop);

    expect(static function () use ($runtime, $writer): void {
        $runtime->run(
            static function (CoroutineScope $scope) use ($writer): void {
                (new \ReflectionMethod(ResponseTransfer::class, 'stream'))->invoke(null, $scope, 'not-a-stream', $writer);
            },
        );
    })->toThrow(InvalidArgumentException::class);

    $source = responseTransferSource('x');
    try {
        expect(static function () use ($runtime, $source, $writer): void {
            $runtime->run(
                static fn(CoroutineScope $scope): int => ResponseTransfer::stream(
                    $scope,
                    $source,
                    $writer,
                    chunkBytes: 0,
                ),
            );
        })->toThrow(InvalidArgumentException::class);
    } finally {
        if (get_resource_type($source) !== 'Unknown') {
            fclose($source);
        }
    }
});

/**
 * @return array{resource, resource}
 * @throws \RuntimeException
 */
function runwireResponseTransferTestSocketPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false || !isset($pair[0], $pair[1])) {
        throw new \RuntimeException('Unable to create a complete test socket pair.');
    }

    return [$pair[0], $pair[1]];
}
