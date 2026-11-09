<?php

declare(strict_types=1);

require_once __DIR__ . '/../benchmarks/ProtocolCoreBench.php';

require_once __DIR__ . '/../benchmarks/CoroutineRuntimeBench.php';
require_once __DIR__ . '/../benchmarks/HostAdapterBench.php';
require_once __DIR__ . '/../benchmarks/LifecycleRuntimeBench.php';
require_once __DIR__ . '/../benchmarks/AdaptiveProtocolBench.php';

it('executes real protocol benchmark fixtures through their decoders and validators', function (): void {
    $benchmark = new ProtocolCoreBench();

    expect($benchmark->benchHttp1HeadValidation())->toBe(512)
        ->and($benchmark->benchHttp2FrameParse())->toBe(1_024)
        ->and($benchmark->benchHttp2FragmentedFrameParse())->toBe(1_024)
        ->and($benchmark->benchHttp2HpackDecode())->toBe(7)
        ->and($benchmark->benchHttp2HpackEncode())->toBeGreaterThan(0)
        ->and($benchmark->benchHttp2HuffmanDecode())->toBe(strlen('https://example.test/benchmark'))
        ->and($benchmark->benchHttp3FrameDecode())->toBe(1_024)
        ->and($benchmark->benchHttp3QpackStaticDecode())->toBe(7)
        ->and($benchmark->benchHttp3QpackStaticEncode())->toBeGreaterThan(0);
});

it('checks coroutine benchmark checksums and repeated fixture initialization', function (): void {
    $benchmark = new \Infocyph\Runwire\Benchmarks\CoroutineRuntimeBench();
    for ($trial = 0; $trial < 2; ++$trial) {
        $benchmark->setUp();
        expect($benchmark->benchBufferedChannelThroughput())->toBe(36)
            ->and($benchmark->benchUnbufferedChannelHandoff())->toBe(36)
            ->and($benchmark->benchFutureAwaitResolve())->toBe(1)
            ->and($benchmark->benchRequestRootCoroutine())->toBe(1)
            ->and($benchmark->benchSchedulerYieldResume())->toBe(1)
            ->and($benchmark->benchSemaphoreAcquireRelease())->toBe(1)
            ->and($benchmark->benchTaskCreateStartComplete())->toBe(1)
            ->and($benchmark->benchTaskGroupSpawnJoin())->toBe(4)
            ->and($benchmark->benchRequestContextWithoutCoroutine())->toBe(32);
    }
});

it('checks host adapter benchmark normalization and response completion', function (): void {
    $benchmark = new \Infocyph\Runwire\Benchmarks\HostAdapterBench();
    for ($trial = 0; $trial < 2; ++$trial) {
        $benchmark->setUp();
        expect($benchmark->benchApplicationDispatchAndCleanup())->toBe(4)
            ->and($benchmark->benchHostRequestNormalization())->toBe(strlen('/benchmark?driver=host'))
            ->and($benchmark->benchHostResponseWriterLifecycle())->toBe(0);
    }
});

it('checks lifecycle benchmark accounting and reset between trials', function (): void {
    $benchmark = new \Infocyph\Runwire\Benchmarks\LifecycleRuntimeBench();
    for ($trial = 0; $trial < 2; ++$trial) {
        $benchmark->setUp();
        expect($benchmark->benchMetricsRequestAccounting())->toBe(1)
            ->and($benchmark->benchMetricsRequestAccounting())->toBe(2)
            ->and($benchmark->benchMetricsSnapshot())->toBeGreaterThan(0)
            ->and($benchmark->benchRequestContextLifecycle())->toBe(32)
            ->and($benchmark->benchRequestResetterRegistry())->toBe(0)
            ->and($benchmark->benchWorkerRecycleAccounting())->toBe(1);
    }
});

it('checks adaptive benchmark fixtures exercise distinct bounded load states', function (): void {
    $benchmark = new \Infocyph\Runwire\Benchmarks\AdaptiveProtocolBench();
    for ($trial = 0; $trial < 2; ++$trial) {
        $benchmark->setUp();
        expect($benchmark->benchHttp1AutoHigh())->toBeFalse()
            ->and($benchmark->benchHttp1AutoLow())->toBeTrue()
            ->and($benchmark->benchHttp1Fixed())->toBeTrue()
            ->and($benchmark->benchHttp2AutoHigh())->toBe(256)
            ->and($benchmark->benchHttp2AutoLow())->toBe(1_024)
            ->and($benchmark->benchHttp2Fixed())->toBe(1_024)
            ->and($benchmark->benchHttp3PumpAutoHigh())->toBe(320)
            ->and($benchmark->benchHttp3PumpAutoLow())->toBe(128)
            ->and($benchmark->benchHttp3PumpFixed())->toBe(320)
            ->and($benchmark->benchHttp3ResponseAutoHigh())->toBe(128)
            ->and($benchmark->benchHttp3ResponseAutoLow())->toBe(32)
            ->and($benchmark->benchHttp3ResponseFixed())->toBe(128);
    }
});
