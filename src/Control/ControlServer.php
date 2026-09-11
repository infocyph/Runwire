<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Control;

use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Network\UnixListener;
use Infocyph\Runwire\Network\UnixListenerOptions;
use Infocyph\Runwire\Network\WriteState;
use Infocyph\Runwire\Protocol\FramedConnection;
use Infocyph\Runwire\Protocol\LineCodec;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\SupervisorStatus;
use Infocyph\Runwire\Supervisor\WorkerStatus;
use JsonException;
use LogicException;

final class ControlServer
{
    public const int PROTOCOL_VERSION = 1;

    private ?UnixListener $listener = null;

    private ?LoopInterface $loop = null;

    private ?Supervisor $supervisor = null;

    public function __construct(private readonly ControlOptions $options) {}

    public function close(bool $unlinkPath = true): void
    {
        $this->listener?->abortConnections();
        $this->listener?->close($unlinkPath);
        $this->listener = null;
        $this->loop = null;
        $this->supervisor = null;
    }

    public function closeInheritedInChild(): void
    {
        $this->close(false);
    }

    public function open(LoopInterface $loop, Supervisor $supervisor): void
    {
        if ($this->listener !== null) {
            throw new LogicException('Control server is already open.');
        }

        $receiveMax = max(4_096, $this->options->maxRequestBytes + 1);
        $sendMax = max(4_096, $this->options->maxResponseBytes + 1);
        $listenerOptions = new ListenerOptions(
            backlog: min(128, $this->options->maxConnections),
            maxConnections: $this->options->maxConnections,
            acceptBatchSize: min(16, $this->options->maxConnections),
        );
        $limits = new ConnectionLimits(
            readChunkBytes: min(16_384, $receiveMax),
            maxReadBytesPerTick: $receiveMax,
            receiveLowWatermarkBytes: max(1, intdiv($receiveMax, 4)),
            receiveHighWatermarkBytes: max(2, intdiv($receiveMax, 2)),
            maxReceiveBufferBytes: $receiveMax,
            sendLowWatermarkBytes: max(1, intdiv($sendMax, 4)),
            sendHighWatermarkBytes: max(2, intdiv($sendMax, 2)),
            maxSendBufferBytes: $sendMax,
            maxWriteBytesPerTick: min(262_144, $sendMax),
            idleTimeoutSeconds: $this->options->idleTimeoutSeconds,
            lifetimeTimeoutSeconds: $this->options->lifetimeTimeoutSeconds,
        );

        $this->loop = $loop;
        $this->supervisor = $supervisor;
        $this->listener = UnixListener::bind(
            $this->options->path,
            new UnixListenerOptions(
                listener: $listenerOptions,
                removeStaleSocket: true,
                permissions: $this->options->permissions,
                unlinkOnClose: true,
            ),
            $limits,
        );
        $this->listener->start($loop, function (Connection $connection) use ($loop): void {
            new FramedConnection(
                $loop,
                $connection,
                new LineCodec("\n", $this->options->maxRequestBytes),
                function (string $frame, FramedConnection $session): void {
                    $this->handle($frame, $session);
                },
                maxFramesPerTick: 8,
            );
        });
    }

    public function path(): string
    {
        return $this->options->path;
    }

    /** @return array<string, mixed> */
    private static function workerArray(WorkerStatus $worker): array
    {
        return [
            'group' => $worker->group,
            'slot' => $worker->slot,
            'pid' => $worker->pid,
            'generation' => $worker->generation,
            'state' => $worker->state->value,
            'restart_count' => $worker->restartCount,
            'started_at_monotonic' => $worker->startedAtMonotonic,
            'age_seconds' => $worker->ageSeconds,
            'current' => $worker->current,
            'replaces_pid' => $worker->replacesPid,
        ];
    }

    private function handle(string $frame, FramedConnection $session): void
    {
        try {
            $request = json_decode($frame, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->respondError($session, 'invalid_json', 'Control request must be valid JSON.');

            return;
        }
        if (!is_array($request) || array_is_list($request)) {
            $this->respondError($session, 'invalid_request', 'Control request must be a JSON object.');

            return;
        }
        /** @var array<string, mixed> $request */
        if (($request['version'] ?? null) !== self::PROTOCOL_VERSION) {
            $this->respondError($session, 'unsupported_version', 'Unsupported control protocol version.');

            return;
        }
        $action = $request['action'] ?? null;
        if (!is_string($action)) {
            $this->respondError($session, 'invalid_request', 'Control action must be a string.');

            return;
        }
        if ($action !== 'status' && !$this->matchesRuntime($request['runtime_id'] ?? null)) {
            $this->respondError($session, 'runtime_mismatch', 'Control request runtime identity does not match.');

            return;
        }

        match ($action) {
            'status' => $this->respond($session, ['status' => $this->statusArray()]),
            'reload' => $this->reload($session),
            'recycle' => $this->recycle($request, $session),
            'stop' => $this->stop($request, $session),
            default => $this->respondError($session, 'unknown_action', 'Unknown control action.'),
        };
    }

    private function matchesRuntime(mixed $runtimeId): bool
    {
        $expected = $this->supervisor?->status()->runtimeId;

        return is_string($runtimeId) && is_string($expected) && hash_equals($expected, $runtimeId);
    }

    /** @param array<string, mixed> $request */
    private function recycle(array $request, FramedConnection $session): void
    {
        $group = $request['group'] ?? null;
        $slot = $request['slot'] ?? null;
        if (!is_string($group) || !is_int($slot)) {
            $this->respondError($session, 'invalid_request', 'Recycle requires string group and integer slot.');

            return;
        }

        try {
            $accepted = $this->supervisor?->recycle($group, $slot) ?? false;
        } catch (LogicException $error) {
            $this->respondError($session, 'invalid_target', $error->getMessage());

            return;
        }
        $this->respond($session, ['accepted' => $accepted]);
    }

    private function reload(FramedConnection $session): void
    {
        $this->supervisor?->reload();
        $this->respond($session, [
            'accepted' => true,
            'generation' => $this->supervisor?->status()->generation,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function respond(FramedConnection $session, array $data): void
    {
        $this->write($session, [
            'version' => self::PROTOCOL_VERSION,
            'ok' => true,
            'runtime_id' => $this->supervisor?->status()->runtimeId,
            'data' => $data,
        ]);
    }

    private function respondError(FramedConnection $session, string $code, string $message): void
    {
        $this->write($session, [
            'version' => self::PROTOCOL_VERSION,
            'ok' => false,
            'runtime_id' => $this->supervisor?->status()->runtimeId,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }

    /** @return array<string, mixed> */
    private function statusArray(): array
    {
        $status = $this->supervisor?->status();
        if (!$status instanceof SupervisorStatus) {
            return [];
        }

        return [
            'runtime_id' => $status->runtimeId,
            'master_pid' => $status->masterPid,
            'started_at_unix' => $status->startedAtUnix,
            'uptime_seconds' => $status->uptimeSeconds,
            'running' => $status->running,
            'stopping' => $status->stopping,
            'reloading' => $status->reloading,
            'reload_queued' => $status->reloadQueued,
            'generation' => $status->generation,
            'worker_count' => $status->workerCount,
            'current_worker_count' => $status->currentWorkerCount,
            'ready_worker_count' => $status->readyWorkerCount,
            'pending_restart_count' => $status->pendingRestartCount,
            'lifecycle_listener_failures' => $status->lifecycleListenerFailures,
            'workers' => array_map(self::workerArray(...), $status->workers),
        ];
    }

    /** @param array<string, mixed> $request */
    private function stop(array $request, FramedConnection $session): void
    {
        $force = $request['force'] ?? false;
        if (!is_bool($force)) {
            $this->respondError($session, 'invalid_request', 'Stop force flag must be boolean.');

            return;
        }

        $this->respond($session, ['accepted' => true, 'force' => $force]);
        $session->closeGracefully();
        $this->loop?->defer(function () use ($force): void {
            $this->supervisor?->stop($force);
        });
    }

    /** @param array<string, mixed> $payload */
    private function write(FramedConnection $session, array $payload): void
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            $session->abort();

            return;
        }
        if (strlen($json) > $this->options->maxResponseBytes) {
            $json = sprintf(
                '{"version":%d,"ok":false,"error":{"code":"response_too_large","message":"Control response exceeded configured limit."}}',
                self::PROTOCOL_VERSION,
            );
        }

        $result = $session->transport()->write($json . "\n");
        if ($result->state === WriteState::REJECTED_LIMIT || $result->state === WriteState::CLOSED) {
            $session->abort();
        }
    }
}
