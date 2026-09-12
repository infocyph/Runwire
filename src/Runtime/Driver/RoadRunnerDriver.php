<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Driver;

use Closure;
use Infocyph\Runwire\RoadRunnerOptions;
use Infocyph\Runwire\Runtime\Host\HostDriverInterface;
use Infocyph\Runwire\Runtime\Host\RoadRunnerResponseWriter;
use Infocyph\Runwire\Runtime\Host\RoadRunnerSession;
use Infocyph\Runwire\Runtime\Host\RoadRunnerSessionInterface;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;

final class RoadRunnerDriver implements HostDriverInterface
{
    /** @var Closure(): RoadRunnerSessionInterface */
    private readonly Closure $sessionFactory;

    private ?RoadRunnerSessionInterface $session = null;

    /** @param callable(): RoadRunnerSessionInterface|null $sessionFactory */
    public function __construct(
        private readonly RoadRunnerOptions $options,
        ?callable $sessionFactory = null,
    ) {
        $this->sessionFactory = $sessionFactory === null
            ? RoadRunnerSession::create(...)
            : Closure::fromCallable($sessionFactory);
    }

    public function run(RuntimeApplication $application): void
    {
        $session = ($this->sessionFactory)();
        $this->session = $session;

        try {
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

    private function runRequests(RoadRunnerSessionInterface $session, RuntimeApplication $application): void
    {
        $handled = 0;
        while (($request = $session->waitRequest($this->options->maxRequestBodyBytes)) !== null) {
            $writer = new RoadRunnerResponseWriter(
                $session,
                $this->options->maxResponseBytes,
                strtoupper($request->method) === 'HEAD',
            );
            $application->handle($request, $writer);
            if (!$writer->isEnded()) {
                $writer->end();
            }

            ++$handled;
            gc_collect_cycles();
            if ($this->options->maxRequests !== 0 && $handled >= $this->options->maxRequests) {
                $session->stop();
            }
        }
    }
}
