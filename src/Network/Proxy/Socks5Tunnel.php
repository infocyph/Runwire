<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Proxy;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\OutboundAddressPolicy;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Experimental nonblocking SOCKS5 CONNECT over one established proxy socket.
 *
 * Credentials and original-destination TLS are not enabled until a verified
 * proxy TLS owner and nested original-peer verification can be guaranteed.
 */
final class Socks5Tunnel
{
    /** @var Closure(?Connection, ?Throwable):void */
    private readonly Closure $completion;

    private ?int $closeObserver = null;

    private string $phase = 'new';

    private string $reply = '';

    private ?CancellationSubscription $subscription = null;

    private ?int $timer = null;

    /** @param callable(?Connection, ?Throwable):void $completion */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Connection $connection,
        private readonly Socks5Handshake $handshake,
        private readonly string $host,
        private readonly int $port,
        callable $completion,
        private readonly float $timeoutSeconds = 10.0,
        private readonly ?CancellationToken $token = null,
        private readonly ?OutboundAddressPolicy $targetAddressPolicy = null,
    ) {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 300) {
            throw new InvalidArgumentException('SOCKS5 tunnel timeout is invalid.');
        }

        $this->completion = Closure::fromCallable($completion);
    }

    /** Cancel the pending proxy handshake exactly once, retiring all owned resources. */
    public function cancel(): bool
    {
        if (!$this->pending()) {
            return false;
        }
        $this->fail(new CancelledException(CancellationReason::HOST_CANCELLED));

        return true;
    }

    /** Start one bounded nonblocking proxy handshake with exclusive callback ownership. */
    public function start(): void
    {
        if ($this->phase !== 'new') {
            throw new LogicException('SOCKS5 tunnel may start only once.');
        }
        $this->token?->throwIfCancelled();
        if ($this->handshake->usesAuthentication()) {
            throw new LogicException('SOCKS5 password exchange requires a verified proxy TLS owner.');
        }
        // Validate DNS disclosure policy and target before writing any bytes.
        $this->handshake->connectRequest($this->host, $this->port);
        $this->assertTargetPolicy();
        $this->phase = 'method';
        try {
            $this->connection->claimCallbacks(
                $this,
                function (Connection $connection): void {
                    $this->receive($connection);
                },
                static function (): void {
                    return;
                },
                function (): void {
                    $this->fail(new RuntimeException('Proxy closed during SOCKS5 handshake.'));
                },
            );
            if (!$this->pending()) {
                return;
            }
            $this->closeObserver = $this->connection->registerCloseObserver(
                function (): void {
                    $this->fail(new RuntimeException('SOCKS5 proxy connection closed during negotiation.'));
                },
            );
            if (!$this->pending()) {
                return;
            }
            $delay = $this->timeoutSeconds;
            $remaining = $this->token?->deadline()->remainingSeconds();
            if ($remaining !== null) {
                $delay = min($delay, $remaining);
            }
            $this->timer = $this->loop->delay(max(0, $delay), function (): void {
                $this->fail(new RuntimeException('SOCKS5 negotiation timed out.'));
            });
            $this->subscription = $this->token?->onCancel(function (CancellationToken $token): void {
                $this->fail(new CancelledException($token->reason() ?? CancellationReason::HOST_CANCELLED));
            });
            if ($this->pending() && !$this->connection->write($this->handshake->greeting())->accepted()) {
                $this->fail(new RuntimeException('SOCKS5 greeting exceeds connection write budget.'));
            }
        } catch (Throwable $error) {
            if ($this->pending()) {
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
        $this->connection->releaseCallbacks($this);
    }

    /** Execute the bounded proxy exchange phase. */
    private function fail(Throwable $error): void
    {
        if (!$this->pending()) {
            return;
        }
        $this->phase = 'closed';
        $this->cleanup();
        $this->connection->abort();
        ($this->completion)(null, $error);
    }

    /** Execute the bounded proxy exchange phase. */
    private function pending(): bool
    {
        return $this->phase === 'method' || $this->phase === 'connect';
    }

    /** Execute the bounded proxy exchange phase. */
    private function readConnectReply(Connection $connection): void
    {
        $size = $this->handshake->replyLength($this->reply);
        if ($size === null) {
            return;
        }
        if ($size !== strlen($this->reply)) {
            throw new RuntimeException('SOCKS5 reply boundary mismatch.');
        }
        $this->phase = 'ready';
        $this->cleanup();
        ($this->completion)($connection, null);
    }

    /** Execute the bounded proxy exchange phase. */
    private function readMethodReply(Connection $connection): void
    {
        if (strlen($this->reply) < 2) {
            return;
        }
        $this->handshake->verifyMethod($this->reply);
        $this->reply = '';
        $this->phase = 'connect';
        if (!$connection->write($this->handshake->connectRequest($this->host, $this->port))->accepted()) {
            throw new RuntimeException('SOCKS5 CONNECT request exceeds connection write budget.');
        }
    }

    /** Execute the bounded proxy exchange phase. */
    private function receive(Connection $connection): void
    {
        if (!$this->pending()) {
            return;
        }

        try {
            while ($connection->receivedBytes() > 0 && $this->pending()) {
                $this->reply .= $connection->read(1);
                if ($this->phase === 'method') {
                    $this->readMethodReply($connection);
                } else {
                    $this->readConnectReply($connection);
                }
            }
        } catch (Throwable $error) {
            $this->fail($error);
        }
    }
}
