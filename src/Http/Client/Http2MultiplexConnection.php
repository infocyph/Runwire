<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Client\Internal\Http2ClientMultiplexSession;
use Infocyph\Runwire\Http\Http2\FrameParser;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Experimental shared-socket HTTP/2 client transport on caller-verified TLS+h2.
 *
 * Does not dial sockets or pool across connections. Response callbacks own
 * only their stream; cancellation sends RST without terminating siblings.
 */
final class Http2MultiplexConnection
{
    private readonly FrameParser $frames;

    private readonly Closure $readyCallback;

    private readonly Http2ClientMultiplexSession $session;

    private ?int $closeObserver = null;

    private string $outgoing = '';

    private bool $ownsCallbacks = false;

    private bool $readyNotified = false;

    /** @var array<int, array{timer:?int, subscription:?CancellationSubscription}> */
    private array $requestWatchers = [];

    private bool $settled = false;

    private bool $started = false;

    /** @param callable(Http2MultiplexConnection):void $onReady */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Connection $connection,
        callable $onReady,
        int $maxConcurrentStreams = 16,
    ) {
        if ($connection->state() !== ConnectionState::OPEN
            || !$connection->isEncrypted()
            || $connection->negotiatedProtocol() !== 'h2') {
            throw new InvalidArgumentException('H2 multiplex transport requires an open verified TLS+h2 connection.');
        }
        $this->frames = new FrameParser();
        $this->readyCallback = Closure::fromCallable($onReady);
        $this->session = new Http2ClientMultiplexSession($maxConcurrentStreams);
    }

    /** Number of currently admitted responses across one established connection. */
    public function activeCount(): int
    {
        return $this->session->activeCount();
    }

    /** Cancel only the selected response, retaining unrelated stream owners. */
    public function cancel(int $streamId): void
    {
        if ($this->settled) {
            return;
        }
        $this->queue($this->session->cancel($streamId));
        $this->pump();
    }

    /** Abort one owned socket and complete every pending stream exactly once. */
    public function close(?Throwable $error = null): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;

        try {
            $this->session->close($error);
        } finally {
            foreach (array_keys($this->requestWatchers) as $streamId) {
                $this->releaseRequest($streamId);
            }
            if ($this->closeObserver !== null) {
                $this->connection->unregisterCloseObserver($this->closeObserver);
                $this->closeObserver = null;
            }
            if ($this->ownsCallbacks) {
                $this->connection->releaseCallbacks($this);
                $this->ownsCallbacks = false;
            }
            $this->connection->abort();
        }
    }

    /**
     * Admit one verified same-origin request after the peer SETTINGS arrived.
     *
     * @param callable(int,\Infocyph\Runwire\Http\Headers):void $onHeaders
     * @param callable(string):void $onBody
     * @param callable(?Throwable):void $onComplete
     */
    public function request(
        ClientRequest $request,
        callable $onHeaders,
        callable $onBody,
        callable $onComplete,
    ): int {
        if (!$this->started || $this->settled || !$this->session->ready()
            || strlen($this->outgoing) > 240_000) {
            throw new RuntimeException('H2 multiplex connection is not available for request admission.');
        }
        $seconds = self::requestDeadline($request);
        $token = $request->cancellation;

        $streamId = null;
        $result = $this->session->request(
            $request,
            $onHeaders,
            $onBody,
            function (?Throwable $error) use (&$streamId, $onComplete): void {
                $this->releaseRequest((int) $streamId);
                $onComplete($error);
            },
        );
        $streamId = $result['stream'];
        $this->requestWatchers[$streamId] = ['timer' => null, 'subscription' => null];
        $this->queue($result['wire']);

        $this->requestWatchers[$streamId]['timer'] = $this->loop->delay($seconds, function () use ($streamId): void {
            $this->cancel($streamId);
        });
        $subscription = $token?->onCancel(function () use ($streamId): void {
            $this->cancel($streamId);
        });
        if (isset($this->requestWatchers[$streamId])) {
            $this->requestWatchers[$streamId]['subscription'] = $subscription;
        } else {
            $subscription?->unsubscribe();
        }
        $this->pump();

        return $streamId;
    }

    /** Claim the socket callbacks and publish the HTTP/2 preface exactly once. */
    public function start(): void
    {
        if ($this->started || $this->settled || !$this->connection->isPoolIdleSafe()) {
            throw new InvalidArgumentException('H2 multiplex connection requires a fresh verified socket.');
        }
        $this->started = true;
        $this->queue($this->session->start());

        try {
            $this->connection->handoffCallbacks(
                $this,
                function (): void {
                    $this->consume();
                },
                function (): void {
                    $this->pump();
                },
                function (): void {
                    $this->close(new RuntimeException('HTTP/2 multiplex peer closed before response completion.'));
                },
            );
            if ($this->settled) {
                return;
            }
            $this->ownsCallbacks = true;
            $this->closeObserver = $this->connection->registerCloseObserver(function (): void {
                $this->close(new RuntimeException('HTTP/2 multiplex transport was closed.'));
            });
            $this->pump();
        } catch (Throwable $error) {
            $this->close($error);
        }
    }

    /** Enforce cancellation and the shortest monotonic request deadline. */
    private static function requestDeadline(ClientRequest $request): float
    {
        $token = $request->cancellation;
        if ($token?->isCancelled()) {
            throw new CancelledException($token->reason() ?? CancellationReason::HOST_CANCELLED);
        }
        $seconds = min(
            10.0,
            $request->deadline?->remainingSeconds() ?? INF,
            $token?->deadline()->remainingSeconds() ?? INF,
        );
        if ($seconds <= 0) {
            throw new RuntimeException('HTTP/2 request deadline has expired.');
        }

        return $seconds;
    }

    private function consume(): void
    {
        if ($this->settled) {
            return;
        }

        try {
            for ($turn = 0; $turn < 8; ++$turn) {
                $bytes = $this->connection->receivedBytes() > 0
                    ? $this->connection->read(8_192)
                    : '';
                $this->processFrames($bytes);
                if ($this->settled) {
                    return;
                }
                if ($this->connection->receivedBytes() === 0 && !$this->frames->hasCompleteFrame()) {
                    break;
                }
            }
            $this->pump();
            if ($this->connection->receivedBytes() > 0 || $this->frames->hasCompleteFrame()) {
                $this->loop->defer(function (): void {
                    $this->consume();
                });
            }
        } catch (Throwable $error) {
            $this->close($error);
        }
    }

    /** Dispatch frames and emit connection-ready callback after validated SETTINGS. */
    private function processFrames(string $bytes): void
    {
        foreach ($this->frames->push($bytes, 32) as $frame) {
            $this->queue($this->session->receive($frame));
            if (!$this->readyNotified && $this->session->ready()) {
                $this->readyNotified = true;
                ($this->readyCallback)($this);
            }
            if ($this->settled) {
                return;
            }
        }
    }

    private function pump(): void
    {
        if ($this->settled || $this->outgoing === '') {
            return;
        }

        try {
            for ($count = 0; $count < 8 && $this->outgoing !== ''; ++$count) {
                $available = $this->connection->availableWriteBytes();
                if ($available <= 0) {
                    return;
                }
                $chunk = substr($this->outgoing, 0, min(8_192, $available));
                if (!$this->connection->write($chunk)->accepted()) {
                    throw new RuntimeException('HTTP/2 multiplex write budget exhausted.');
                }
                $this->outgoing = substr($this->outgoing, strlen($chunk));
            }
            if ($this->outgoing !== '' && !$this->connection->isWritePressured()) {
                $this->loop->defer(function (): void {
                    $this->pump();
                });
            }
        } catch (Throwable $error) {
            $this->close($error);
        }
    }

    private function queue(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        if (strlen($bytes) > 262_144 - strlen($this->outgoing)) {
            throw new RuntimeException('H2 multiplex transport control queue exceeded its memory budget.');
        }
        $this->outgoing .= $bytes;
    }

    private function releaseRequest(int $streamId): void
    {
        $watchers = $this->requestWatchers[$streamId] ?? null;
        if ($watchers === null) {
            return;
        }
        unset($this->requestWatchers[$streamId]);
        $watchers['subscription']?->unsubscribe();
        if ($watchers['timer'] !== null) {
            $this->loop->cancel($watchers['timer']);
        }
    }
}
