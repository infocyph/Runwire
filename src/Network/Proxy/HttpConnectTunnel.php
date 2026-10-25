<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Proxy;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\ConnectionState;
use Infocyph\Runwire\Network\OutboundAddressPolicy;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Experimental nonblocking HTTP CONNECT tunnel on one already established proxy stream.
 *
 * Only unauthenticated tunnel setup is available until independently verified
 * proxy TLS identity and target TLS upgrade are implemented. Does not negotiate
 * end-to-end TLS to the original target.
 */
final class HttpConnectTunnel
{
    /** @var Closure(?Connection, ?Throwable):void */
    private readonly Closure $completion;

    private ?int $closeObserver = null;

    private string $header = '';

    private string $state = 'new';

    private ?CancellationSubscription $subscription = null;

    private ?int $timer = null;

    /** @param callable(?Connection, ?Throwable):void $completion */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Connection $connection,
        private readonly HttpConnectHandshake $handshake,
        private readonly string $host,
        private readonly int $port,
        callable $completion,
        private readonly float $timeoutSeconds = 10.0,
        private readonly ?CancellationToken $token = null,
        private readonly ?OutboundAddressPolicy $targetAddressPolicy = null,
    ) {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 300) {
            throw new InvalidArgumentException('HTTP CONNECT tunnel timeout is invalid.');
        }

        $this->completion = Closure::fromCallable($completion);
    }

    /** Cancel the pending proxy handshake exactly once, retiring all owned resources. */
    public function cancel(): bool
    {
        if ($this->state !== 'pending') {
            return false;
        }
        $this->fail(new CancelledException(CancellationReason::HOST_CANCELLED));

        return true;
    }

    /** Start one bounded nonblocking proxy handshake with exclusive callback ownership. */
    public function start(): void
    {
        if ($this->state !== 'new') {
            throw new LogicException('HTTP CONNECT tunnel may start exactly once.');
        }
        $this->token?->throwIfCancelled();
        if ($this->handshake->usesAuthentication()) {
            throw new LogicException('Authenticated HTTP CONNECT requires a verified proxy TLS owner.');
        }

        $request = $this->handshake->request($this->host, $this->port);
        $this->assertTargetPolicy();
        $this->state = 'pending';

        try {
            $this->connection->claimCallbacks(
                $this,
                function (Connection $connection): void {
                    $this->receive($connection);
                },
                $this->onDrain(...),
                function (): void {
                    $this->fail(new RuntimeException('Proxy closed before CONNECT tunnel establishment.'));
                },
            );
            if ($this->state !== 'pending') {
                return;
            }
            $this->closeObserver = $this->connection->registerCloseObserver(
                function (): void {
                    $this->fail(new RuntimeException('Proxy connection closed during CONNECT negotiation.'));
                },
            );
            if ($this->state !== 'pending') {
                return;
            }
            $delay = $this->timeoutSeconds;
            $remaining = $this->token?->deadline()->remainingSeconds();
            if ($remaining !== null) {
                $delay = min($delay, $remaining);
            }
            $this->timer = $this->loop->delay(max(0, $delay), function (): void {
                $this->fail(new RuntimeException('HTTP CONNECT tunnel negotiation timed out.'));
            });
            $this->subscription = $this->token?->onCancel(function (CancellationToken $token): void {
                $this->fail(new CancelledException($token->reason() ?? CancellationReason::HOST_CANCELLED));
            });
            if ($this->state !== 'pending') {
                return;
            }
            if (!$this->connection->write($request)->accepted()) {
                $this->fail(new RuntimeException('Proxy CONNECT request could not enter bounded send queue.'));
            }
        } catch (Throwable $error) {
            if ($this->state === 'pending') {
                $this->fail($error);
            } else {
                throw $error;
            }
        }
    }

    /** Execute the bounded proxy exchange phase. */
    /** Fail closed when a remote proxy cannot prove an approved destination IP. */
    private function assertTargetPolicy(): void
    {
        if ($this->targetAddressPolicy === null) {
            return;
        }
        $target = trim($this->host, '[]');
        if (filter_var($target, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('Proxy destination IP policy requires a pre-resolved numeric target.');
        }
        $this->targetAddressPolicy->assertCandidate($target);
    }

    private function cleanup(): void
    {
        if ($this->timer !== null) {
            $this->loop->cancel($this->timer);
            $this->timer = null;
        }
        $this->subscription?->unsubscribe();
        $this->subscription = null;
        if ($this->closeObserver !== null) {
            $this->connection->unregisterCloseObserver($this->closeObserver);
            $this->closeObserver = null;
        }
        if ($this->connection->callbacksOwnedBy($this)) {
            $this->connection->releaseCallbacks($this);
        }
    }

    /** Execute the bounded proxy exchange phase. */
    private function fail(Throwable $error): void
    {
        if ($this->state !== 'pending') {
            return;
        }
        $this->state = 'closed';
        $ownsTransport = $this->connection->callbacksOwnedBy($this);
        $this->cleanup();
        if ($ownsTransport) {
            $this->connection->abort();
        }
        ($this->completion)(null, $error);
    }

    /** Reject a closed proxy transport during send-drain handoff. */
    private function onDrain(Connection $connection): void
    {
        if ($connection->state() === ConnectionState::CLOSED) {
            $this->fail(new RuntimeException('Proxy connection closed before tunnel negotiation completed.'));
        }
    }

    /** Execute the bounded proxy exchange phase. */
    private function receive(Connection $connection): void
    {
        if ($this->state !== 'pending') {
            return;
        }

        try {
            while ($connection->receivedBytes() > 0) {
                // Consume only CONNECT header bytes. Any bytes following the
                // 200 response remain in Connection for the next protocol owner.
                $this->header .= $connection->read(1);
                $length = $this->handshake->responseLength($this->header);
                if ($length === null) {
                    continue;
                }
                if ($length !== strlen($this->header)) {
                    throw new RuntimeException('CONNECT header boundary is inconsistent.');
                }
                $this->state = 'ready';
                $this->cleanup();
                ($this->completion)($connection, null);

                return;
            }
        } catch (Throwable $error) {
            $this->fail($error);
        }
    }
}
