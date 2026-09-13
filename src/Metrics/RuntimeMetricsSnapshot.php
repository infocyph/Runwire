<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

use Infocyph\Runwire\Metrics\Enum\ApplicationErrorClass;
use Infocyph\Runwire\Metrics\Enum\ProtocolMetric;

final readonly class RuntimeMetricsSnapshot
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param array<string, int> $errors
     * @param array<string, int> $protocol
     */
    public function __construct(
        public int $sampledAtMonotonicNanoseconds,
        public int $requestsTotal = 0,
        public int $requestsActive = 0,
        public int $requestsFailedTotal = 0,
        public int $connectionsActive = 0,
        public int $connectionsAcceptedTotal = 0,
        public int $connectionsPeak = 0,
        public int $streamsPeak = 0,
        public int $bytesReadTotal = 0,
        public int $bytesWrittenTotal = 0,
        public int $memoryCurrentBytes = 0,
        public int $memoryPeakBytes = 0,
        public int $timersActive = 0,
        public int $deferredBacklog = 0,
        public int $eventLoopTickNanoseconds = 0,
        public int $eventLoopLagNanoseconds = 0,
        public int $callbackOverrunsTotal = 0,
        public int $backpressureEventsTotal = 0,
        public int $rejectedConnectionsTotal = 0,
        public int $rejectedRequestsTotal = 0,
        public int $requestLifetimeHighWaterNanoseconds = 0,
        public int $connectionLifetimeHighWaterNanoseconds = 0,
        public int $requestMemoryDeltaHighWaterBytes = 0,
        public float $workerAgeSeconds = 0.0,
        public float $workerBusySeconds = 0.0,
        public array $protocol = [],
        public array $errors = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return null;
        }

        return new self(
            sampledAtMonotonicNanoseconds: self::integer($data, 'sampled_at_monotonic_ns'),
            requestsTotal: self::integer($data, 'requests_total'),
            requestsActive: self::integer($data, 'requests_active'),
            requestsFailedTotal: self::integer($data, 'requests_failed_total'),
            connectionsActive: self::integer($data, 'connections_active'),
            connectionsAcceptedTotal: self::integer($data, 'connections_accepted_total'),
            connectionsPeak: self::integer($data, 'connections_peak'),
            streamsPeak: self::integer($data, 'streams_peak'),
            bytesReadTotal: self::integer($data, 'bytes_read_total'),
            bytesWrittenTotal: self::integer($data, 'bytes_written_total'),
            memoryCurrentBytes: self::integer($data, 'memory_current_bytes'),
            memoryPeakBytes: self::integer($data, 'memory_peak_bytes'),
            timersActive: self::integer($data, 'timers_active'),
            deferredBacklog: self::integer($data, 'deferred_backlog'),
            eventLoopTickNanoseconds: self::integer($data, 'event_loop_tick_ns'),
            eventLoopLagNanoseconds: self::integer($data, 'event_loop_lag_ns'),
            callbackOverrunsTotal: self::integer($data, 'callback_overruns_total'),
            backpressureEventsTotal: self::integer($data, 'backpressure_events_total'),
            rejectedConnectionsTotal: self::integer($data, 'rejected_connections_total'),
            rejectedRequestsTotal: self::integer($data, 'rejected_requests_total'),
            requestLifetimeHighWaterNanoseconds: self::integer($data, 'request_lifetime_high_water_ns'),
            connectionLifetimeHighWaterNanoseconds: self::integer($data, 'connection_lifetime_high_water_ns'),
            requestMemoryDeltaHighWaterBytes: self::integer($data, 'request_memory_delta_high_water_bytes'),
            workerAgeSeconds: self::number($data, 'worker_age_seconds'),
            workerBusySeconds: self::number($data, 'worker_busy_seconds'),
            protocol: self::bounded($data['protocol'] ?? null, ProtocolMetric::cases()),
            errors: self::bounded($data['errors'] ?? null, ApplicationErrorClass::cases()),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'sampled_at_monotonic_ns' => $this->sampledAtMonotonicNanoseconds,
            'requests_total' => $this->requestsTotal,
            'requests_active' => $this->requestsActive,
            'requests_failed_total' => $this->requestsFailedTotal,
            'connections_active' => $this->connectionsActive,
            'connections_accepted_total' => $this->connectionsAcceptedTotal,
            'connections_peak' => $this->connectionsPeak,
            'streams_peak' => $this->streamsPeak,
            'bytes_read_total' => $this->bytesReadTotal,
            'bytes_written_total' => $this->bytesWrittenTotal,
            'memory_current_bytes' => $this->memoryCurrentBytes,
            'memory_peak_bytes' => $this->memoryPeakBytes,
            'timers_active' => $this->timersActive,
            'deferred_backlog' => $this->deferredBacklog,
            'event_loop_tick_ns' => $this->eventLoopTickNanoseconds,
            'event_loop_lag_ns' => $this->eventLoopLagNanoseconds,
            'callback_overruns_total' => $this->callbackOverrunsTotal,
            'backpressure_events_total' => $this->backpressureEventsTotal,
            'rejected_connections_total' => $this->rejectedConnectionsTotal,
            'rejected_requests_total' => $this->rejectedRequestsTotal,
            'request_lifetime_high_water_ns' => $this->requestLifetimeHighWaterNanoseconds,
            'connection_lifetime_high_water_ns' => $this->connectionLifetimeHighWaterNanoseconds,
            'request_memory_delta_high_water_bytes' => $this->requestMemoryDeltaHighWaterBytes,
            'worker_age_seconds' => $this->workerAgeSeconds,
            'worker_busy_seconds' => $this->workerBusySeconds,
            'protocol' => $this->protocol,
            'errors' => $this->errors,
        ];
    }

    /**
     * @param array<int, ApplicationErrorClass|ProtocolMetric> $allowed
     * @return array<string, int>
     */
    private static function bounded(mixed $values, array $allowed): array
    {
        $source = is_array($values) ? $values : [];
        $result = [];

        foreach ($allowed as $item) {
            $value = $source[$item->value] ?? 0;
            $result[$item->value] = is_int($value) && $value >= 0 ? $value : 0;
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    private static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? 0;

        return is_int($value) && $value >= 0 ? $value : 0;
    }

    /** @param array<string, mixed> $data */
    private static function number(array $data, string $key): float
    {
        $value = $data[$key] ?? 0.0;

        return is_int($value) || is_float($value) ? max(0.0, (float) $value) : 0.0;
    }
}
