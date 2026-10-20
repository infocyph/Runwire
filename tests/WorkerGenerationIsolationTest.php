<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Enum\TaskState;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerRole;
use Infocyph\Runwire\Supervisor\WorkerContext;

it('invalidates background admission and periodic handles when a worker generation closes', function (): void {
    $loop = new SelectLoop();
    $worker = new WorkerContext(
        group: 'generation-isolation',
        slot: 0,
        generation: 1,
        pid: getmypid(),
        parentPid: 0,
        role: WorkerRole::TASK,
    );
    $worker->attachLoop($loop);

    $calls = 0;
    $oldHandle = $worker->every('heartbeat', 0.01, static function () use (&$calls): void {
        ++$calls;
    });

    expect($worker->acceptingBackgroundWork())->toBeTrue();
    $worker->close();
    $worker->close();

    expect($worker->stopping())->toBeTrue()
        ->and($worker->acceptingBackgroundWork())->toBeFalse()
        ->and($worker->backgroundTaskCount())->toBe(0)
        ->and($oldHandle->cancel())->toBeFalse()
        ->and($loop->diagnostics()->timersActive)->toBe(0)
        ->and($loop->diagnostics()->readWatchers)->toBe(0)
        ->and($loop->diagnostics()->writeWatchers)->toBe(0)
        ->and($calls)->toBe(0);

    expect(fn() => $worker->spawnBackground(static function (): void {}))
        ->toThrow(LogicException::class);
    expect(fn() => $worker->every('late-task', 0.01, static function (): void {}))
        ->toThrow(LogicException::class);
});

it('isolates cancelled coroutine and periodic work from a replacement worker generation', function (): void {
    $priorTickCount = 0;
    $replacementTickCount = 0;
    $oldLoop = new SelectLoop();
    $old = new WorkerContext(
        group: 'generation-isolation',
        slot: 0,
        generation: 1,
        pid: getmypid(),
        parentPid: 0,
        role: WorkerRole::TASK,
    );
    $old->attachLoop($oldLoop, 0.1);
    $oldHandle = $old->every('heartbeat', 0.001, static function () use (&$priorTickCount): void {
        ++$priorTickCount;
    });
    $task = $old->spawnBackground(static function (CoroutineScope $scope): void {
        $scope->sleep(60.0);
    });
    $oldLoop->delay(0.01, static function () use ($old): void {
        $old->requestStop(ShutdownReason::DEPLOYMENT_RELOAD);
    });
    try {
        $oldLoop->run();
        expect($old->stopping())->toBeTrue()
            ->and($old->acceptingBackgroundWork())->toBeFalse()
            ->and($task->state())->toBe(TaskState::CANCELLED)
            ->and($old->backgroundTaskCount())->toBe(0);
    } finally {
        $old->close();
    }

    $retiredTickCount = $priorTickCount;
    expect($oldHandle->cancel())->toBeFalse()
        ->and($oldLoop->diagnostics()->timersActive)->toBe(0);

    $newLoop = new SelectLoop();
    $replacement = new WorkerContext(
        group: 'generation-isolation',
        slot: 0,
        generation: 2,
        pid: getmypid(),
        parentPid: 0,
        role: WorkerRole::TASK,
    );
    $replacement->attachLoop($newLoop);
    $newHandle = $replacement->every('heartbeat', 0.001, static function () use (&$replacementTickCount): void {
        ++$replacementTickCount;
    });
    $newLoop->delay(0.02, static function () use ($replacement): void {
        $replacement->requestStop(ShutdownReason::DEPLOYMENT_RELOAD);
    });

    try {
        $newLoop->run();

        expect($replacement->generation)->toBe(2)
            ->and($replacementTickCount)->toBeGreaterThan(0)
            ->and($priorTickCount)->toBe($retiredTickCount)
            ->and($newHandle->cancel())->toBeTrue()
            ->and($replacement->acceptingBackgroundWork())->toBeFalse();
    } finally {
        $replacement->close();
    }

    expect($newLoop->diagnostics()->timersActive)->toBe(0);
});

it('rejects retired application references and completed request contexts after replacement', function (): void {
    $retiredCalls = 0;
    $newCalls = 0;
    $retired = new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$retiredCalls): void {
            unset($request);
            ++$retiredCalls;
            $writer->end('retired');
        },
    );
    $replacement = new RuntimeApplication(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$newCalls): void {
            unset($request);
            ++$newCalls;
            $writer->end('replacement');
        },
    );
    $request = static fn(): HttpRequest => new HttpRequest(
        'GET',
        '/generation',
        ProtocolVersion::HTTP_1_1,
        new Headers(),
        new BufferedRequestBody(''),
    );
    $writer = static fn(): CallbackResponseWriter => new CallbackResponseWriter(
        static function (int $status, Headers $headers): void {
            unset($headers);
            expect($status)->toBe(200);
        },
        static function (string $body): void {
            expect($body)->not->toBe('');
        },
        static function (): void {},
        1_024,
    );

    $retiredRequest = $request();
    $retired->handle($retiredRequest, $writer());
    expect($retiredCalls)->toBe(1)
        ->and($retiredRequest->context->completed())->toBeTrue();
    $retired->shutdown(ShutdownReason::DEPLOYMENT_RELOAD);

    expect(fn() => $retired->handle($request(), $writer()))
        ->toThrow(LogicException::class);
    expect(fn() => $replacement->handle($retiredRequest, $writer()))
        ->toThrow(LogicException::class);

    $freshRequest = $request();
    $freshWriter = $writer();
    $replacement->handle($freshRequest, $freshWriter);

    expect($newCalls)->toBe(1)
        ->and($retiredCalls)->toBe(1)
        ->and($replacement->healthy())->toBeTrue()
        ->and($freshRequest->context->completed())->toBeTrue()
        ->and($freshWriter->isEnded())->toBeTrue();
    $replacement->shutdown(ShutdownReason::DEPLOYMENT_RELOAD);
});
