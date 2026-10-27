<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Internal\QuicWorkerCommand;
use Infocyph\Runwire\RequestDeadline;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Native outbound QUIC session owned exclusively by one bounded PHP worker.
 *
 * No native object crosses the ProcessExecutor boundary; the reactor only
 * queues verified stream commands and receives bounded decoded results.
 * Fatal command failure or cancellation retires the dedicated worker and
 * invalidates all associated native streams.
 *
 * @experimental H3 stream framing and performance certification remain open.
 */
final class QuicClientSession
{
    private readonly ProcessExecutor $executor;

    private readonly int $ownerPid;

    private int $epoch = 0;

    private int $nextRequestId = 1;

    private string $state = 'new';

    /** Construct one dedicated QUIC process to preserve native session ownership. */
    public function __construct(LoopInterface $loop, int $maxQueuedOperations = 64)
    {
        $this->executor = new ProcessExecutor($loop, 1, $maxQueuedOperations);
        $pid = getmypid();
        if (!is_int($pid)) {
            throw new RuntimeException('Unable to establish QUIC session process ownership.');
        }
        $this->ownerPid = $pid;
    }

    /** Whether this session still has a valid dedicated native worker. */
    public function active(): bool
    {
        $this->assertProcessOwner();

        return $this->state === 'ready';
    }

    /**
     * Enqueue one bounded worker-local stream command.
     *
     * @param callable(mixed, ?Throwable): void $completion
     */
    public function command(
        string $operation,
        int $streamId,
        string $data,
        bool $fin,
        callable $completion,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int {
        $this->assertProcessOwner();
        if ($this->state !== 'ready') {
            throw new LogicException('QUIC worker session must be connected and alive.');
        }
        $payload = QuicWorkerCommand::encode($this->nextRequestId++, $operation, $streamId, $data, $fin);

        try {
            return $this->executor->submit(
                'quic_session',
                ['epoch' => $this->epoch, 'command' => $payload],
                function (mixed $value, ?Throwable $error) use ($completion, $operation): void {
                    if ($error !== null || !is_array($value)) {
                        $this->dispose();
                        $completion(null, $error ?? new RuntimeException('QUIC worker returned an invalid stream result.'));

                        return;
                    }
                    if ($operation === 'close') {
                        $this->dispose();
                    }
                    $completion($value, null);
                },
                $token,
                $deadline,
            );
        } catch (Throwable $error) {
            $this->dispose();

            throw $error;
        }
    }

    /**
     * Open a verified TLS 1.3 QUIC session off-loop and retain its native owner.
     *
     * @param callable(mixed, ?Throwable): void $completion
     */
    public function connect(
        string $host,
        int $port,
        QuicClientOptions $options,
        callable $completion,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int {
        $this->assertProcessOwner();
        if ($this->state !== 'new') {
            throw new LogicException('QUIC session has already been opened or closed.');
        }
        if ($host === '' || strlen($host) > 253 || preg_match('/[\x00-\x20\x7f]/', $host) === 1
            || $port < 1 || $port > 65_535) {
            throw new LogicException('Invalid outbound QUIC host or port.');
        }

        $args = [
            'action' => 'connect',
            'host' => $host,
            'port' => $port,
            'options' => $options->nativeOptions(),
            'maxStreams' => $options->maxOpenStreams,
        ];
        $handshake = RequestDeadline::afterSeconds($options->connectTimeoutMilliseconds / 1_000, hrtime(true));
        if ($deadline === null || $deadline->monotonicNanoseconds === null
            || ($handshake->monotonicNanoseconds !== null && $handshake->monotonicNanoseconds < $deadline->monotonicNanoseconds)) {
            $deadline = $handshake;
        }

        $this->state = 'connecting';

        try {
            return $this->executor->submit(
                'quic_session',
                $args,
                function (mixed $value, ?Throwable $error) use ($completion): void {
                    if ($error !== null || !is_array($value)
                        || !is_int($value['epoch'] ?? null)
                        || ($value['verified'] ?? null) !== true
                        || !is_string($value['alpn'] ?? null)) {
                        $this->dispose();
                        $completion(null, $error ?? new RuntimeException('Worker returned an invalid verified QUIC session.'));

                        return;
                    }
                    $this->epoch = $value['epoch'];
                    $this->state = 'ready';
                    $completion($value, null);
                },
                $token,
                $deadline,
            );
        } catch (Throwable $error) {
            $this->dispose();

            throw $error;
        }
    }

    /** Abort pending operations and destroy worker-local QUIC resources. */
    public function dispose(): void
    {
        $this->assertProcessOwner();
        if ($this->state === 'closed') {
            return;
        }
        $this->state = 'closed';
        $this->executor->close();
    }
    private function assertProcessOwner(): void
    {
        if (getmypid() !== $this->ownerPid) {
            throw new LogicException('QUIC sessions cannot cross a fork; connect in the owning child process.');
        }
    }

}
