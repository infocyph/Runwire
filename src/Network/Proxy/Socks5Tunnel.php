<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Proxy;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Enum\CloseReason;
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
    private ?int $closeObserver = null;

    private ?CancellationSubscription $subscription = null;

    private ?int $timer = null;

    private string $phase = 'new';

    private string $reply = '';

    /** @var Closure(?Connection, ?Throwable):void */
    private readonly Closure $completion;

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
    ) {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 300) {
            throw new InvalidArgumentException('SOCKS5 tunnel timeout is invalid.');
        }

        $this->completion = Closure::fromCallable($completion);
    }

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
        $this->phase = 'method';
        try {
            $this->connection->claimCallbacks(
                $this,
                function (Connection $connection): void {
                    $this->receive($connection);
                },
                static function (): void {},
                function (): void {
                    $this->fail(new RuntimeException('Proxy closed during SOCKS5 handshake.'));
                },
            );
            if (!$this->pending()) {
                return;
            }
            $this->closeObserver = $this->connection->registerCloseObserver(
                function (Connection $connection, CloseReason $reason): void {
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

    public function cancel(): bool
    {
        if (!$this->pending()) {
            return false;
        }
        $this->fail(new CancelledException(CancellationReason::HOST_CANCELLED));

        return true;
    }

    private function pending(): bool
    {
        return $this->phase === 'method' || $this->phase === 'connect';
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

    private function receive(Connection $connection): void
    {
        if (!$this->pending()) {
            return;
        }

        try {
            while ($connection->receivedBytes() > 0 && $this->pending()) {
                $this->reply .= $connection->read(1);
                if ($this->phase === 'method') {
                    if (strlen($this->reply) < 2) {
                        continue;
                    }
                    $this->handshake->verifyMethod($this->reply);
                    $this->reply = '';
                    $this->phase = 'connect';
                    if (!$connection->write($this->handshake->connectRequest($this->host, $this->port))->accepted()) {
                        throw new RuntimeException('SOCKS5 CONNECT request exceeds connection write budget.');
                    }

                    continue;
                }
                $size = $this->handshake->replyLength($this->reply);
                if ($size === null) {
                    continue;
                }
                if ($size !== strlen($this->reply)) {
                    throw new RuntimeException('SOCKS5 reply boundary mismatch.');
                }
                $this->phase = 'ready';
                $this->cleanup();
                ($this->completion)($connection, null);

                return;
            }
        } catch (Throwable $error) {
            $this->fail($error);
        }
    }
}
