<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Benchmarks;

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Metrics\RuntimeMetrics;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Internal\WorkerRecycleState;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\Runtime\RequestResetterInterface;
use Infocyph\Runwire\Runtime\RequestResetterRegistry;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[BeforeMethods('setUp')]
#[Iterations(5)]
#[Revs(1_000)]
#[Warmup(1)]
final class LifecycleRuntimeBench
{
    private RuntimeMetrics $metrics;

    private WorkerRecycleState $recycle;

    private RequestExecutionPolicy $requestPolicy;

    private RequestResetterRegistry $resetters;

    private RuntimeContext $runtime;

    /**
     * Initialize fixtures before PHPBench's per-iteration setup refresh.
     * @throws \InvalidArgumentException
     */
    public function __construct()
    {
        $this->setUp();
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function setUp(): void
    {
        $this->metrics = new RuntimeMetrics();
        $pid = getmypid();
        $this->runtime = RuntimeContext::fromCapabilities(
            new RuntimeCapabilities(
                driver: RuntimeDriver::NATIVE,
                persistentProcess: true,
                persistentApplication: true,
                ownsListener: true,
                ownsEventLoop: true,
                ownsWorkerPool: true,
                supportsGracefulReload: true,
                supportsWorkerRecycle: true,
                supportsHttp1: true,
                supportsHttp2: true,
                supportsHttp3: true,
            ),
            mode: 'benchmark',
            workerSlot: 0,
            generation: 1,
            pid: $pid === false ? 0 : $pid,
            concurrent: false,
            metrics: $this->metrics,
        );
        $this->requestPolicy = new RequestExecutionPolicy(maxExecutionSeconds: 1.0);
        $this->resetters = new RequestResetterRegistry([
            new class implements RequestResetterInterface {
                /**
                 * @throws \InvalidArgumentException
                 */
                #[\Override]
                public function reset(RequestContext $context): void
                {
                    $context->removeAttribute('benchmark');
                }
            },
        ]);
        $this->recycle = new WorkerRecycleState(
            new WorkerRecyclePolicy(
                maxRequests: 1_000_000,
                maxLifetimeSeconds: 86_400,
                maxMemoryBytes: 1_073_741_824,
            ),
            seed: 1,
            startedAtNs: 1_000_000_000,
        );
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function benchMetricsRequestAccounting(): int
    {
        $context = RequestContext::create($this->runtime, $this->requestPolicy);
        $this->metrics->requestStarted(ProtocolVersion::HTTP_1_1);
        $this->metrics->requestCompleted($context, ProtocolVersion::HTTP_1_1, memory_get_usage(true));
        $context->complete();

        return $this->metrics->snapshot()->requestsTotal;
    }

    public function benchMetricsSnapshot(): int
    {
        return $this->metrics->snapshot()->memoryCurrentBytes;
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \OverflowException
     */
    public function benchRequestContextLifecycle(): int
    {
        $context = RequestContext::create($this->runtime, $this->requestPolicy);
        $context->setAttribute('benchmark', 1);
        $context->complete();

        return strlen($context->requestId);
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \OverflowException
     */
    public function benchRequestResetterRegistry(): int
    {
        $context = RequestContext::create($this->runtime, $this->requestPolicy);
        $context->setAttribute('benchmark', 1);
        $failures = $this->resetters->reset($context);
        $context->complete();

        return count($failures);
    }

    public function benchWorkerRecycleAccounting(): int
    {
        $this->recycle->recordRequestCompleted(
            nowNs: 1_000_000_001,
            currentMemoryBytes: 4_194_304,
            peakMemoryBytes: 4_194_304,
        );

        return $this->recycle->requestsTotal();
    }
}
