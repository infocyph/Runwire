<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor;

use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Metrics\RuntimeMetricsSnapshot;
use Infocyph\Runwire\Runtime\AdmissionPolicy;
use Infocyph\Runwire\Runtime\Internal\WorkerRecycleState;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerRole;
use Infocyph\Runwire\Supervisor\Internal\PeriodicTaskRegistry;
use RuntimeException;

final class WorkerContext
{
    private const int MAX_DIAGNOSTIC_MESSAGE_BYTES = 6_144;

    private readonly PeriodicTaskRegistry $periodicTasks;

    private readonly WorkerRecycleState $recycleState;

    private int $activeRequests = 0;

    private string $lifecycleBuffer = '';

    private bool $ready = false;

    private bool $recycling = false;

    private ShutdownReason $shutdownReason = ShutdownReason::SUPERVISOR_STOP;

    private bool $stopping = false;

    /** @var resource|null */
    private mixed $stopRead = null;

    /** @var resource|null */
    private mixed $stopWrite = null;

    /** @param resource $readyStream */
    public function __construct(
        public readonly string $group,
        public readonly int $slot,
        public readonly int $generation,
        public readonly int $pid,
        public readonly int $parentPid,
        private readonly mixed $readyStream,
        public readonly WorkerRecyclePolicy $recyclePolicy = new WorkerRecyclePolicy(),
        public readonly AdmissionPolicy $admissionPolicy = new AdmissionPolicy(),
        public readonly WorkerRole $role = WorkerRole::CUSTOM,
    ) {
        if (!stream_set_blocking($this->readyStream, false)) {
            throw new RuntimeException('Unable to configure worker lifecycle channel.');
        }

        $this->periodicTasks = new PeriodicTaskRegistry();
        $this->recycleState = new WorkerRecycleState(
            $this->recyclePolicy,
            seed: $pid ^ ($slot << 8) ^ ($generation << 16),
        );
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!is_array($pair) || count($pair) !== 2) {
            throw new RuntimeException('Unable to create worker stop wake channel.');
        }

        [$this->stopRead, $this->stopWrite] = $pair;
        if (!stream_set_blocking($this->stopRead, false) || !stream_set_blocking($this->stopWrite, false)) {
            $this->close();

            throw new RuntimeException('Unable to configure worker stop wake channel.');
        }
    }

    public function acceptingBackgroundWork(): bool
    {
        return !$this->stopping;
    }

    public function attachLoop(LoopInterface $loop): void
    {
        $this->periodicTasks->attach($loop);
    }

    public function close(): void
    {
        $this->periodicTasks->close();
        if (is_resource($this->readyStream)) {
            fclose($this->readyStream);
        }

        foreach (['stopRead', 'stopWrite'] as $property) {
            if (is_resource($this->{$property})) {
                fclose($this->{$property});
            }
            $this->{$property} = null;
        }
    }

    public function consumeStopWake(): void
    {
        if (!is_resource($this->stopRead)) {
            return;
        }

        do {
            $chunk = fread($this->stopRead, 8_192);
        } while (is_string($chunk) && $chunk !== '');
    }

    public function currentMemoryBytes(): int
    {
        return $this->recycleState->currentMemoryBytes();
    }

    public function effectiveMaxLifetimeSeconds(): int
    {
        return $this->recycleState->effectiveMaxLifetimeSeconds();
    }

    public function effectiveMaxRequests(): int
    {
        return $this->recycleState->effectiveMaxRequests();
    }

    /** @param callable(): void $callback */
    public function every(string $name, float $intervalSeconds, callable $callback): PeriodicTaskHandle
    {
        return $this->periodicTasks->register($name, $intervalSeconds, $callback);
    }

    public function markUnhealthy(): void
    {
        $this->signal('U');
    }

    public function peakMemoryBytes(): int
    {
        return $this->recycleState->peakMemoryBytes();
    }

    public function ready(): void
    {
        if ($this->ready) {
            return;
        }

        $this->signal('R');
        $this->ready = true;
    }

    public function recordRequestCompleted(): bool
    {
        if ($this->activeRequests > 0) {
            --$this->activeRequests;
            if ($this->activeRequests === 0 && !$this->stopping) {
                $this->signal('I');
            }
        }

        $recycle = $this->recycleState->recordRequestCompleted();
        if ($recycle) {
            $this->requestRecycle(
                $this->recycleState->recycleReason() ?? ShutdownReason::RECYCLE_REQUEST_LIMIT,
            );
        }

        return $recycle;
    }

    public function recordRequestStarted(): void
    {
        ++$this->activeRequests;
        if ($this->activeRequests === 1 && !$this->stopping) {
            $this->signal('B');
        }
    }

    public function recycling(): bool
    {
        return $this->recycling;
    }

    public function reportDeadlineExceeded(string $requestId): void
    {
        if ($requestId === '' || strlen($requestId) > 128) {
            return;
        }

        $this->signalDiagnostic('D:' . $requestId);
    }

    public function reportMetrics(RuntimeMetricsSnapshot $snapshot): void
    {
        $json = json_encode($snapshot->toArray(), JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        $this->signalDiagnostic('M:' . $json);
    }

    public function requestRecycle(ShutdownReason $reason = ShutdownReason::MANUAL_RECYCLE): void
    {
        if ($this->recycling) {
            return;
        }

        $this->recycling = true;
        $this->signal('X:' . $reason->value);
        $this->requestStop($reason);
    }

    public function requestStop(?ShutdownReason $reason = null): void
    {
        if ($this->stopping) {
            return;
        }

        $this->readControl();
        $this->shutdownReason = $reason ?? $this->shutdownReason;
        $this->stopping = true;
        $this->periodicTasks->drain();
        if (is_resource($this->stopWrite)) {
            fwrite($this->stopWrite, 'S');
        }
    }

    public function requestsTotal(): int
    {
        return $this->recycleState->requestsTotal();
    }

    public function shutdownReason(): ShutdownReason
    {
        $this->readControl();

        return $this->shutdownReason;
    }

    public function stopping(): bool
    {
        return $this->stopping;
    }

    /** @return resource */
    public function stopStream(): mixed
    {
        if (!is_resource($this->stopRead)) {
            throw new RuntimeException('Worker stop wake channel is closed.');
        }

        return $this->stopRead;
    }

    private function readControl(): void
    {
        if (!is_resource($this->readyStream)) {
            return;
        }

        do {
            $chunk = fread($this->readyStream, 1_024);
            if (is_string($chunk) && $chunk !== '') {
                $this->lifecycleBuffer .= $chunk;
            }
        } while (is_string($chunk) && $chunk !== '');

        while (($newline = strpos($this->lifecycleBuffer, "\n")) !== false) {
            $message = substr($this->lifecycleBuffer, 0, $newline);
            $this->lifecycleBuffer = substr($this->lifecycleBuffer, $newline + 1);
            if (!str_starts_with($message, 'S:')) {
                continue;
            }
            $reason = ShutdownReason::tryFrom(substr($message, 2));
            if ($reason !== null) {
                $this->shutdownReason = $reason;
            }
        }
    }

    private function signal(string $message): void
    {
        if (!is_resource($this->readyStream)) {
            return;
        }

        $payload = $message . "\n";
        $written = fwrite($this->readyStream, $payload);
        if ($written !== strlen($payload)) {
            throw new RuntimeException('Unable to signal worker lifecycle state.');
        }
    }

    private function signalDiagnostic(string $message): void
    {
        if (!is_resource($this->readyStream) || strlen($message) > self::MAX_DIAGNOSTIC_MESSAGE_BYTES) {
            return;
        }

        $payload = $message . "\n";
        fwrite($this->readyStream, $payload);
    }
}
