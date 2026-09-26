<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

use Closure;
use Infocyph\Runwire\Http\Internal\StreamingRequestBody;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\WebSocket\WebSocketOptions;
use Infocyph\Runwire\WebSocket\WebSocketSession;
use RuntimeException;

/**
 * Owns HTTP/1 to WebSocket transport handoff without expanding parser responsibility.
 *
 * @internal
 */
final class WebSocketUpgradeOwner
{
    /** @var Closure(): void */
    private readonly Closure $beforeHandoff;

    private readonly Connection $connection;

    private readonly Http1Input $input;

    private readonly LoopInterface $loop;

    private ?WebSocketSession $session = null;

    /**
     * Create a handoff owner for one HTTP/1 connection.
     *
     * @param callable(): void $beforeHandoff
     */
    public function __construct(
        LoopInterface $ownerLoop,
        Connection $ownedConnection,
        Http1Input $httpInput,
        callable $beforeHandoff,
    ) {
        /** @var Closure(): void $handoff */
        $handoff = $beforeHandoff(...);
        $this->beforeHandoff = $handoff;
        $this->connection = self::retainConnection($ownedConnection);
        $this->input = self::retainInput($httpInput);
        $this->loop = self::retainLoop($ownerLoop);
    }

    private static function retainConnection(Connection $connection): Connection
    {
        return $connection;
    }

    private static function retainInput(Http1Input $input): Http1Input
    {
        return $input;
    }

    private static function retainLoop(LoopInterface $loop): LoopInterface
    {
        return $loop;
    }

    /**
     * Determine whether transport ownership has moved to WebSocket.
     */
    public function active(): bool
    {
        return $this->session !== null;
    }

    /**
     * Drain an active WebSocket session.
     */
    public function drain(): bool
    {
        if ($this->session === null) {
            return false;
        }

        $this->session->drain();

        return true;
    }

    /**
     * Queue the 101 response and transfer the existing connection to a WebSocket session.
     */
    public function upgrade(
        string $accept,
        ?string $subprotocol,
        WebSocketOptions $options,
        ?StreamingRequestBody $body,
    ): WebSocketSession {
        if ($this->session !== null) {
            throw new RuntimeException('HTTP/1 connection cannot upgrade after protocol ownership changed.');
        }
        if ($body !== null && !$body->eof()) {
            throw new RuntimeException('WebSocket upgrade requires a fully consumed empty HTTP request body.');
        }

        $wire = "HTTP/1.1 101 Switching Protocols\r\n"
            . "upgrade: websocket\r\n"
            . "connection: Upgrade\r\n"
            . 'sec-websocket-accept: ' . $accept . "\r\n"
            . ($subprotocol === null ? '' : 'sec-websocket-protocol: ' . $subprotocol . "\r\n")
            . "\r\n";
        $result = $this->connection->write($wire);
        if (!$result->accepted()) {
            throw new RuntimeException('Unable to queue native WebSocket upgrade response.');
        }

        ($this->beforeHandoff)();
        $initialBytes = $this->input->take($this->input->availableBytes());
        $this->session = new WebSocketSession(
            $this->loop,
            $this->connection,
            $options,
            $subprotocol,
            $initialBytes,
        );

        return $this->session;
    }
}
