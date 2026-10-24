<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Internal\MonotonicTime;
use Infocyph\Runwire\Loop\LoopDiagnosticsSnapshot;
use Infocyph\Runwire\Metrics\Enum\ApplicationErrorClass;
use Infocyph\Runwire\Metrics\Enum\ProtocolMetric;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

/**
 * Accumulates bounded runtime, protocol, request, connection, and error metrics.
 */
final class RuntimeMetrics implements MetricsProviderInterface
{
    private readonly int $startedAtNanoseconds;

    private int $backpressureEventsTotal = 0;

    private int $bytesReadTotal = 0;

    private int $bytesWrittenTotal = 0;

    private int $callbackOverrunsTotal = 0;

    private int $connectionLifetimeHighWaterNanoseconds = 0;

    private int $connectionsAcceptedTotal = 0;

    private int $connectionsActive = 0;

    private int $connectionsPeak = 0;

    private int $deferredBacklog = 0;

    /** @var array<string, int> */
    private array $errors;

    private int $eventLoopLagNanoseconds = 0;

    private int $eventLoopTickNanoseconds = 0;

    private int $lastGcAtNanoseconds;

    private int $lastGcMemoryBytes;

    /** @var array<string, int> */
    private array $protocol;

    private int $queuedBytesCurrent = 0;

    private int $queuedBytesLimit = 0;

    private int $queuedBytesPeak = 0;

    private int $rejectedConnectionsTotal = 0;

    private int $rejectedRequestsTotal = 0;

    private int $requestLifetimeHighWaterNanoseconds = 0;

    private int $requestMemoryDeltaHighWaterBytes = 0;

    private int $requestsActive = 0;

    private int $requestsFailedTotal = 0;

    private int $requestsSinceGc = 0;

    private int $requestsTotal = 0;

    private int $streamsPeak = 0;

    private int $timersActive = 0;

    private ?int $workerBusySinceNanoseconds = null;

    /**
     * Create a runtime metrics accumulator.
     */
    public function __construct(?int $startedAtNanoseconds = null)
    {
        $this->startedAtNanoseconds = $startedAtNanoseconds ?? MonotonicTime::nowNanoseconds();
        $this->lastGcAtNanoseconds = $this->startedAtNanoseconds;
        $this->lastGcMemoryBytes = memory_get_usage(true);
        $this->errors = self::zeroedErrors();
        $this->protocol = self::zeroedProtocol();
    }

    /**
     * Record a closed protocol connection and its final counters.
     */
    public function connectionClosed(
        ProtocolVersion $version,
        int $bytesRead = 0,
        int $bytesWritten = 0,
        int $lifetimeNanoseconds = 0,
        int $backpressureEvents = 0,
    ): void {
        $this->connectionsActive = max(0, $this->connectionsActive - 1);
        $this->bytesReadTotal += max(0, $bytesRead);
        $this->bytesWrittenTotal += max(0, $bytesWritten);
        $this->backpressureEventsTotal += max(0, $backpressureEvents);
        $this->connectionLifetimeHighWaterNanoseconds = max(
            $this->connectionLifetimeHighWaterNanoseconds,
            max(0, $lifetimeNanoseconds),
        );
        $metric = self::connectionActiveMetric($version);
        $this->protocol[$metric->value] = max(0, $this->protocol[$metric->value] - 1);
    }

    /**
     * Record an opened protocol connection.
     */
    public function connectionOpened(ProtocolVersion $version): void
    {
        ++$this->connectionsActive;
        ++$this->connectionsAcceptedTotal;
        $this->connectionsPeak = max($this->connectionsPeak, $this->connectionsActive);
        ++$this->protocol[self::connectionActiveMetric($version)->value];
        ++$this->protocol[self::connectionTotalMetric($version)->value];
    }

    /**
     * Collect cyclic garbage when the configured thresholds are reached.
     */
    public function maybeCollectGarbage(GcPolicy $policy): void
    {
        ++$this->requestsSinceGc;
        if (!$policy->enabled || !gc_enabled()) {
            return;
        }

        $now = MonotonicTime::nowNanoseconds();
        $minimumInterval = MonotonicTime::secondsToNanoseconds($policy->minimumIntervalSeconds);
        if ($now - $this->lastGcAtNanoseconds < $minimumInterval) {
            return;
        }

        $memory = memory_get_usage(true);
        if (!$this->gcThresholdReached($policy, $memory)) {
            return;
        }

        gc_collect_cycles();
        $this->lastGcAtNanoseconds = $now;
        $this->lastGcMemoryBytes = memory_get_usage(true);
        $this->requestsSinceGc = 0;
    }

    /**
     * Merge event-loop diagnostics into runtime metrics.
     */
    public function observeLoop(LoopDiagnosticsSnapshot $diagnostics): void
    {
        $this->timersActive = $diagnostics->timersActive;
        $this->deferredBacklog = $diagnostics->deferredBacklog;
        $this->eventLoopTickNanoseconds = $diagnostics->lastTickNanoseconds;
        $this->eventLoopLagNanoseconds = max($this->eventLoopLagNanoseconds, $diagnostics->maxLagNanoseconds);
        $this->callbackOverrunsTotal = max($this->callbackOverrunsTotal, $diagnostics->callbackOverrunsTotal);
    }

    /**
     * Merge aggregate network counters into runtime metrics.
     */
    public function observeNetwork(
        int $activeConnections,
        int $acceptedConnections,
        int $bytesRead,
        int $bytesWritten,
        int $rejectedConnections,
        int $lifetimeHighWaterNanoseconds = 0,
    ): void {
        $this->connectionsActive = max(0, $activeConnections);
        $this->connectionsAcceptedTotal = max($this->connectionsAcceptedTotal, max(0, $acceptedConnections));
        $this->connectionsPeak = max($this->connectionsPeak, $this->connectionsActive);
        $this->bytesReadTotal = max($this->bytesReadTotal, max(0, $bytesRead));
        $this->bytesWrittenTotal = max($this->bytesWrittenTotal, max(0, $bytesWritten));
        $this->rejectedConnectionsTotal = max($this->rejectedConnectionsTotal, max(0, $rejectedConnections));
        $this->connectionLifetimeHighWaterNanoseconds = max(
            $this->connectionLifetimeHighWaterNanoseconds,
            max(0, $lifetimeHighWaterNanoseconds),
        );
    }

    /**
     * Observe worker-wide Runwire-owned queued-byte pressure.
     */
    public function observeQueuedBytes(int $used, int $limit): void
    {
        $this->queuedBytesCurrent = max(0, $used);
        $this->queuedBytesLimit = max(0, $limit);
        $this->queuedBytesPeak = max($this->queuedBytesPeak, $this->queuedBytesCurrent);
    }

    /**
     * Increment recorded backpressure events.
     */
    public function recordBackpressure(int $events = 1): void
    {
        $this->backpressureEventsTotal += max(0, $events);
    }

    /**
     * Record an application error classification.
     */
    public function recordError(ApplicationErrorClass $error, int $amount = 1, bool $requestFailure = false): void
    {
        if ($amount <= 0) {
            return;
        }

        $this->errors[$error->value] += $amount;
        if ($requestFailure) {
            $this->requestsFailedTotal += $amount;
        }
    }

    /**
     * Record a rejected connection.
     */
    public function recordRejectedConnection(): void
    {
        ++$this->rejectedConnectionsTotal;
    }

    /**
     * Record a rejected request and overload failure.
     */
    public function recordRejectedRequest(): void
    {
        ++$this->rejectedRequestsTotal;
        $this->recordError(ApplicationErrorClass::OVERLOAD_REJECTION, requestFailure: true);
    }

    /**
     * Record completion, resource usage, and failure state for a request.
     */
    public function requestCompleted(
        RequestContext $context,
        ProtocolVersion $version,
        int $memoryAtStartBytes,
        ?ApplicationErrorClass $error = null,
    ): void {
        $this->requestsActive = max(0, $this->requestsActive - 1);
        if ($this->requestsActive === 0) {
            $this->workerBusySinceNanoseconds = null;
        }

        $duration = max(0, MonotonicTime::nowNanoseconds() - $context->startMonotonicNanoseconds);
        $this->requestLifetimeHighWaterNanoseconds = max($this->requestLifetimeHighWaterNanoseconds, $duration);
        $memoryDelta = max(0, memory_get_usage(true) - max(0, $memoryAtStartBytes));
        $this->requestMemoryDeltaHighWaterBytes = max($this->requestMemoryDeltaHighWaterBytes, $memoryDelta);
        $this->completeProtocolRequest($version, $context);

        $error ??= self::cancellationError($context);
        if ($error !== null) {
            $this->recordError($error, requestFailure: true);
        }
    }

    /**
     * Record the start of a request for a protocol version.
     */
    public function requestStarted(ProtocolVersion $version): void
    {
        ++$this->requestsTotal;
        ++$this->requestsActive;
        $this->workerBusySinceNanoseconds ??= MonotonicTime::nowNanoseconds();

        match ($version) {
            ProtocolVersion::HTTP_1_1 => ++$this->protocol[ProtocolMetric::HTTP1_REQUESTS_TOTAL->value],
            ProtocolVersion::HTTP_2 => $this->startStream(
                ProtocolMetric::HTTP2_STREAMS_ACTIVE,
                ProtocolMetric::HTTP2_STREAMS_TOTAL,
            ),
            ProtocolVersion::HTTP_3 => $this->startStream(
                ProtocolMetric::HTTP3_STREAMS_ACTIVE,
                ProtocolMetric::HTTP3_STREAMS_TOTAL,
            ),
        };
    }

    /**
     * Set a protocol metric to a non-negative value.
     */
    public function setProtocol(ProtocolMetric $metric, int $value): void
    {
        $value = max(0, $value);
        $this->protocol[$metric->value] = $value;
        if ($metric === ProtocolMetric::HTTP2_STREAMS_ACTIVE || $metric === ProtocolMetric::HTTP3_STREAMS_ACTIVE) {
            $this->streamsPeak = max($this->streamsPeak, $value);
        }
    }

    /**
     * Capture the current immutable runtime metrics snapshot.
     */
    public function snapshot(): RuntimeMetricsSnapshot
    {
        $now = MonotonicTime::nowNanoseconds();

        return new RuntimeMetricsSnapshot(
            sampledAtMonotonicNanoseconds: $now,
            requestsTotal: $this->requestsTotal,
            requestsActive: $this->requestsActive,
            requestsFailedTotal: $this->requestsFailedTotal,
            connectionsActive: $this->connectionsActive,
            connectionsAcceptedTotal: $this->connectionsAcceptedTotal,
            connectionsPeak: $this->connectionsPeak,
            streamsPeak: $this->streamsPeak,
            bytesReadTotal: $this->bytesReadTotal,
            bytesWrittenTotal: $this->bytesWrittenTotal,
            memoryCurrentBytes: memory_get_usage(true),
            memoryPeakBytes: memory_get_peak_usage(true),
            timersActive: $this->timersActive,
            deferredBacklog: $this->deferredBacklog,
            eventLoopTickNanoseconds: $this->eventLoopTickNanoseconds,
            eventLoopLagNanoseconds: $this->eventLoopLagNanoseconds,
            callbackOverrunsTotal: $this->callbackOverrunsTotal,
            backpressureEventsTotal: $this->backpressureEventsTotal,
            queuedBytesCurrent: $this->queuedBytesCurrent,
            queuedBytesPeak: $this->queuedBytesPeak,
            queuedBytesLimit: $this->queuedBytesLimit,
            rejectedConnectionsTotal: $this->rejectedConnectionsTotal,
            rejectedRequestsTotal: $this->rejectedRequestsTotal,
            requestLifetimeHighWaterNanoseconds: $this->requestLifetimeHighWaterNanoseconds,
            connectionLifetimeHighWaterNanoseconds: $this->connectionLifetimeHighWaterNanoseconds,
            requestMemoryDeltaHighWaterBytes: $this->requestMemoryDeltaHighWaterBytes,
            workerAgeSeconds: max(0.0, ($now - $this->startedAtNanoseconds) / MonotonicTime::NANOSECONDS_PER_SECOND),
            workerBusySeconds: $this->busySeconds($now),
            protocol: $this->protocol,
            errors: $this->errors,
        );
    }

    private static function cancellationError(RequestContext $context): ?ApplicationErrorClass
    {
        return match ($context->cancellation->reason()) {
            CancellationReason::DEADLINE_EXCEEDED => ApplicationErrorClass::DEADLINE_EXCEEDED,
            CancellationReason::SCOPE_FAILED => ApplicationErrorClass::HANDLER_EXCEPTION,
            CancellationReason::TRANSPORT_CANCELLED => ApplicationErrorClass::CLIENT_CANCELLED,
            CancellationReason::HOST_CANCELLED, CancellationReason::WORKER_SHUTDOWN => ApplicationErrorClass::TRANSPORT_ERROR,
            null => null,
        };
    }

    private static function connectionActiveMetric(ProtocolVersion $version): ProtocolMetric
    {
        return match ($version) {
            ProtocolVersion::HTTP_1_1 => ProtocolMetric::HTTP1_CONNECTIONS_ACTIVE,
            ProtocolVersion::HTTP_2 => ProtocolMetric::HTTP2_CONNECTIONS_ACTIVE,
            ProtocolVersion::HTTP_3 => ProtocolMetric::HTTP3_CONNECTIONS_ACTIVE,
        };
    }

    private static function connectionTotalMetric(ProtocolVersion $version): ProtocolMetric
    {
        return match ($version) {
            ProtocolVersion::HTTP_1_1 => ProtocolMetric::HTTP1_CONNECTIONS_TOTAL,
            ProtocolVersion::HTTP_2 => ProtocolMetric::HTTP2_CONNECTIONS_TOTAL,
            ProtocolVersion::HTTP_3 => ProtocolMetric::HTTP3_CONNECTIONS_TOTAL,
        };
    }

    /** @return array<string, int> */
    private static function zeroedErrors(): array
    {
        return array_fill_keys(
            array_map(static fn(ApplicationErrorClass $error): string => $error->value, ApplicationErrorClass::cases()),
            0,
        );
    }

    /** @return array<string, int> */
    private static function zeroedProtocol(): array
    {
        return array_fill_keys(
            array_map(static fn(ProtocolMetric $metric): string => $metric->value, ProtocolMetric::cases()),
            0,
        );
    }

    private function busySeconds(int $now): float
    {
        return $this->workerBusySinceNanoseconds === null
            ? 0.0
            : max(0.0, ($now - $this->workerBusySinceNanoseconds) / MonotonicTime::NANOSECONDS_PER_SECOND);
    }

    private function completeProtocolRequest(ProtocolVersion $version, RequestContext $context): void
    {
        $active = match ($version) {
            ProtocolVersion::HTTP_1_1 => null,
            ProtocolVersion::HTTP_2 => ProtocolMetric::HTTP2_STREAMS_ACTIVE,
            ProtocolVersion::HTTP_3 => ProtocolMetric::HTTP3_STREAMS_ACTIVE,
        };
        if ($active !== null) {
            $this->protocol[$active->value] = max(0, $this->protocol[$active->value] - 1);
        }
        if ($context->cancellation->reason() !== CancellationReason::TRANSPORT_CANCELLED) {
            return;
        }

        $reset = match ($version) {
            ProtocolVersion::HTTP_1_1 => null,
            ProtocolVersion::HTTP_2 => ProtocolMetric::HTTP2_RESETS_TOTAL,
            ProtocolVersion::HTTP_3 => ProtocolMetric::HTTP3_RESETS_TOTAL,
        };
        if ($reset !== null) {
            ++$this->protocol[$reset->value];
        }
    }

    private function gcThresholdReached(GcPolicy $policy, int $memory): bool
    {
        return $this->requestsSinceGc >= $policy->requestInterval
            || ($policy->growthBytes > 0 && $memory - $this->lastGcMemoryBytes >= $policy->growthBytes);
    }

    private function startStream(ProtocolMetric $active, ProtocolMetric $total): void
    {
        ++$this->protocol[$active->value];
        ++$this->protocol[$total->value];
        $this->streamsPeak = max($this->streamsPeak, $this->protocol[$active->value]);
    }
}
