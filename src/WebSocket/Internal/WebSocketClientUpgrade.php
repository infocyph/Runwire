<?php

declare(strict_types=1);

namespace Infocyph\Runwire\WebSocket\Internal;

use Closure;
use Infocyph\Runwire\CancellationSubscription;
use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\DialOptions;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\WebSocket\WebSocketOptions;
use Infocyph\Runwire\WebSocket\WebSocketSession;
use RuntimeException;
use Throwable;

/** @internal Bounded HTTP/1 upgrade state; ownership transfers exactly once to WebSocketSession. */
final class WebSocketClientUpgrade
{
    private readonly Closure $completion;

    private string $head = '';

    private bool $settled = false;

    private ?int $timer = null;

    private ?int $closeObserver = null;

    private ?CancellationSubscription $subscription = null;

    /**
     * @param array{wire:string,key:string,secure:bool,host:string,port:int} $request
     * @param list<string> $subprotocols
     * @param callable(?WebSocketSession, ?Throwable):void $completion
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly Connection $connection,
        private readonly array $request,
        private readonly array $subprotocols,
        private readonly WebSocketOptions $options,
        private readonly DialOptions $dialOptions,
        callable $completion,
    ) {
        $this->completion = Closure::fromCallable($completion);
    }

    /** Begin the bounded outbound upgrade and transfer callbacks on success. */
    public function start(): void
    {
        $deadline = $this->dialOptions->deadline?->remainingSeconds();
        $tokenDeadline = $this->dialOptions->cancellation?->deadline()->remainingSeconds();
        $timeout = min($this->dialOptions->connectTimeoutSeconds, $deadline ?? INF, $tokenDeadline ?? INF);
        if ($timeout <= 0) {
            $this->fail(new RuntimeException('WebSocket upgrade deadline already expired.'));

            return;
        }

        $this->timer = $this->loop->delay($timeout, function (): void {
            $this->fail(new RuntimeException('WebSocket upgrade timed out.'));
        });
        $this->closeObserver = $this->connection->registerCloseObserver(function (): void {
            $this->fail(new RuntimeException('WebSocket peer closed before completing HTTP upgrade.'));
        });
        if ($this->settled) {
            return;
        }
        $this->subscription = $this->dialOptions->cancellation?->onCancel(function (CancellationToken $token): void {
            $this->fail(new CancelledException($token->reason() ?? CancellationReason::HOST_CANCELLED));
        });
        if ($this->settled) {
            return;
        }

        try {
            $this->connection->onEof(function (): void {
                $this->fail(new RuntimeException('WebSocket peer ended its upgrade response.'));
            });
            if ($this->settled) {
                return;
            }
            if (!$this->connection->write($this->request['wire'])->accepted()) {
                $this->fail(new RuntimeException('WebSocket upgrade could not be queued without overflow.'));

                return;
            }
            $this->connection->onData(function (): void {
                $this->consume();
            });
        } catch (Throwable $error) {
            $this->fail($error);
        }
    }

    private function finish(?WebSocketSession $session, ?Throwable $error): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        if ($this->timer !== null) {
            $this->loop->cancel($this->timer);
            $this->timer = null;
        }
        if ($this->closeObserver !== null) {
            $this->connection->unregisterCloseObserver($this->closeObserver);
            $this->closeObserver = null;
        }
        $this->subscription?->unsubscribe();
        $this->subscription = null;
        $this->head = '';
        if ($error !== null) {
            $this->connection->abort();
        }
        ($this->completion)($session, $error);
    }
    private function consume(): void
    {
        if ($this->settled) {
            return;
        }
        // Bound each read to the remaining header allowance plus a small
        // probe. Leave any coalesced WebSocket frames in the connection buffer
        // for the session after the upgrade ownership handoff.
        $remaining = 8_192 - strlen($this->head);
        $this->head .= $this->connection->read(max(1, $remaining + 1));
        $end = strpos($this->head, "\r\n\r\n");
        if ($end === false) {
            if (strlen($this->head) > 8_192) {
                $this->fail(new RuntimeException('WebSocket upgrade response header exceeds 8 KiB.'));
            }

            return;
        }
        if ($end + 4 > 8_192) {
            $this->fail(new RuntimeException('WebSocket upgrade response header exceeds 8 KiB.'));

            return;
        }

        try {
            $selected = WebSocketClientHandshake::verify(
                substr($this->head, 0, $end + 4),
                $this->request['key'],
                $this->subprotocols,
            );
            $session = new WebSocketSession(
                $this->loop,
                $this->connection,
                $this->options,
                $selected,
                substr($this->head, $end + 4),
                clientMode: true,
            );
        } catch (Throwable $error) {
            $this->fail($error);

            return;
        }
        $this->finish($session, null);
    }

    private function fail(Throwable $error): void
    {
        $this->finish(null, $error);
    }

}