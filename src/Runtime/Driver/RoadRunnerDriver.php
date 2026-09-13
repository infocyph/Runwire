<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Driver;

use Closure;
use Infocyph\Runwire\RoadRunnerOptions;
use Infocyph\Runwire\Runtime\Host\HostDriverInterface;
use Infocyph\Runwire\Runtime\Host\RoadRunnerResponseWriter;
use Infocyph\Runwire\Runtime\Host\RoadRunnerSession;
use Infocyph\Runwire\Runtime\Host\RoadRunnerSessionInterface;
use Infocyph\Runwire\Runtime\Internal\WorkerRecycleState;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

final class RoadRunnerDriver implements HostDriverInterface
{
    /** @var Closure(): RoadRunnerSessionInterface */
    private readonly Closure $sessionFactory;

    private ?RoadRunnerSessionInterface $session = null;

    /** @param callable(): RoadRunnerSessionInterface|null $sessionFactory */
    public function __construct(
        private readonly RoadRunnerOptions $options,
        ?callable $sessionFactory = null,
        private readonly WorkerRecyclePolicy $recyclePolicy = new WorkerRecyclePolicy(),
    ) {
        $this->sessionFactory = $sessionFactory === null
            ? RoadRunnerSession::create(...)
            : Closure::fromCallable($sessionFactory);
    }

    public function run(RuntimeApplicationInterface $application): void
    {
        $session = ($this->sessionFactory)();
        $this->session = $session;

        try {
            $application->start();
            $this->runRequests($session, $application);
        } finally {
            $this->session = null;
            $application->shutdown();
        }
    }

    public function stop(): void
    {
        $this->session?->stop();
    }

    private function runRequests(RoadRunnerSessionInterface $session, RuntimeApplicationInterface $application): void
    {
        $recycle = new WorkerRecycleState($this->recyclePolicy);
        while (($request = $session->waitRequest($this->options->maxRequestBodyBytes)) !== null) {
            try {
                $writer = new RoadRunnerResponseWriter(
                    $session,
                    $this->options->maxResponseBytes,
                    strtoupper($request->method) === 'HEAD',
                );
                $application->handle($request, $writer, completeResponse: true);
            } finally {
                gc_collect_cycles();
                if ($recycle->recordRequestCompleted()) {
                    $session->stop();
                }
            }
        }
    }
}
