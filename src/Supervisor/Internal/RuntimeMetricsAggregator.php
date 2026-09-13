<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Supervisor\Internal;

use Infocyph\Runwire\Metrics\Enum\ApplicationErrorClass;
use Infocyph\Runwire\Metrics\Enum\ProtocolMetric;
use Infocyph\Runwire\Metrics\RuntimeMetricsSnapshot;

final class RuntimeMetricsAggregator
{
    /** @param list<RuntimeMetricsSnapshot> $snapshots */
    public static function aggregate(array $snapshots): RuntimeMetricsSnapshot
    {
        $protocol = self::zeroedProtocol();
        $errors = self::zeroedErrors();
        $totals = self::zeroedTotals();
        $highWater = self::zeroedHighWater();
        $sampledAt = 0;

        foreach ($snapshots as $snapshot) {
            $sampledAt = max($sampledAt, $snapshot->sampledAtMonotonicNanoseconds);
            self::addTotals($totals, $snapshot);
            self::maxHighWater($highWater, $snapshot);
            self::addMap($protocol, $snapshot->protocol);
            self::addMap($errors, $snapshot->errors);
        }

        return new RuntimeMetricsSnapshot(
            sampledAtMonotonicNanoseconds: $sampledAt,
            requestsTotal: $totals['requests_total'],
            requestsActive: $totals['requests_active'],
            requestsFailedTotal: $totals['requests_failed'],
            connectionsActive: $totals['connections_active'],
            connectionsAcceptedTotal: $totals['connections_accepted'],
            connectionsPeak: $totals['connections_peak'],
            streamsPeak: $totals['streams_peak'],
            bytesReadTotal: $totals['bytes_read'],
            bytesWrittenTotal: $totals['bytes_written'],
            memoryCurrentBytes: $totals['memory_current'],
            memoryPeakBytes: $totals['memory_peak'],
            timersActive: $totals['timers_active'],
            deferredBacklog: $totals['deferred_backlog'],
            eventLoopTickNanoseconds: $highWater['loop_tick'],
            eventLoopLagNanoseconds: $highWater['loop_lag'],
            callbackOverrunsTotal: $totals['callback_overruns'],
            backpressureEventsTotal: $totals['backpressure_events'],
            rejectedConnectionsTotal: $totals['rejected_connections'],
            rejectedRequestsTotal: $totals['rejected_requests'],
            requestLifetimeHighWaterNanoseconds: $highWater['request_lifetime'],
            connectionLifetimeHighWaterNanoseconds: $highWater['connection_lifetime'],
            requestMemoryDeltaHighWaterBytes: $highWater['request_memory_delta'],
            workerAgeSeconds: $highWater['worker_age'],
            workerBusySeconds: $highWater['worker_busy'],
            protocol: $protocol,
            errors: $errors,
        );
    }

    /** @param array<string, int> $target @param array<string, int> $source */
    private static function addMap(array &$target, array $source): void
    {
        foreach ($target as $key => $value) {
            $target[$key] = $value + max(0, $source[$key] ?? 0);
        }
    }

    /** @param array<string, int> $totals */
    private static function addTotals(array &$totals, RuntimeMetricsSnapshot $snapshot): void
    {
        $totals['requests_total'] += $snapshot->requestsTotal;
        $totals['requests_active'] += $snapshot->requestsActive;
        $totals['requests_failed'] += $snapshot->requestsFailedTotal;
        $totals['connections_active'] += $snapshot->connectionsActive;
        $totals['connections_accepted'] += $snapshot->connectionsAcceptedTotal;
        $totals['connections_peak'] += $snapshot->connectionsPeak;
        $totals['streams_peak'] += $snapshot->streamsPeak;
        $totals['bytes_read'] += $snapshot->bytesReadTotal;
        $totals['bytes_written'] += $snapshot->bytesWrittenTotal;
        $totals['memory_current'] += $snapshot->memoryCurrentBytes;
        $totals['memory_peak'] += $snapshot->memoryPeakBytes;
        $totals['timers_active'] += $snapshot->timersActive;
        $totals['deferred_backlog'] += $snapshot->deferredBacklog;
        $totals['callback_overruns'] += $snapshot->callbackOverrunsTotal;
        $totals['backpressure_events'] += $snapshot->backpressureEventsTotal;
        $totals['rejected_connections'] += $snapshot->rejectedConnectionsTotal;
        $totals['rejected_requests'] += $snapshot->rejectedRequestsTotal;
    }

    /** @return array<string, int> */
    private static function zeroedErrors(): array
    {
        return array_fill_keys(
            array_map(static fn(ApplicationErrorClass $item): string => $item->value, ApplicationErrorClass::cases()),
            0,
        );
    }

    /** @return array<string, int|float> */
    private static function zeroedHighWater(): array
    {
        return [
            'loop_tick' => 0,
            'loop_lag' => 0,
            'request_lifetime' => 0,
            'connection_lifetime' => 0,
            'request_memory_delta' => 0,
            'worker_age' => 0.0,
            'worker_busy' => 0.0,
        ];
    }

    /** @return array<string, int> */
    private static function zeroedProtocol(): array
    {
        return array_fill_keys(
            array_map(static fn(ProtocolMetric $item): string => $item->value, ProtocolMetric::cases()),
            0,
        );
    }

    /** @return array<string, int> */
    private static function zeroedTotals(): array
    {
        return [
            'requests_total' => 0,
            'requests_active' => 0,
            'requests_failed' => 0,
            'connections_active' => 0,
            'connections_accepted' => 0,
            'connections_peak' => 0,
            'streams_peak' => 0,
            'bytes_read' => 0,
            'bytes_written' => 0,
            'memory_current' => 0,
            'memory_peak' => 0,
            'timers_active' => 0,
            'deferred_backlog' => 0,
            'callback_overruns' => 0,
            'backpressure_events' => 0,
            'rejected_connections' => 0,
            'rejected_requests' => 0,
        ];
    }

    /** @param array<string, int|float> $highWater */
    private static function maxHighWater(array &$highWater, RuntimeMetricsSnapshot $snapshot): void
    {
        $highWater['loop_tick'] = max($highWater['loop_tick'], $snapshot->eventLoopTickNanoseconds);
        $highWater['loop_lag'] = max($highWater['loop_lag'], $snapshot->eventLoopLagNanoseconds);
        $highWater['request_lifetime'] = max($highWater['request_lifetime'], $snapshot->requestLifetimeHighWaterNanoseconds);
        $highWater['connection_lifetime'] = max($highWater['connection_lifetime'], $snapshot->connectionLifetimeHighWaterNanoseconds);
        $highWater['request_memory_delta'] = max($highWater['request_memory_delta'], $snapshot->requestMemoryDeltaHighWaterBytes);
        $highWater['worker_age'] = max($highWater['worker_age'], $snapshot->workerAgeSeconds);
        $highWater['worker_busy'] = max($highWater['worker_busy'], $snapshot->workerBusySeconds);
    }
}
