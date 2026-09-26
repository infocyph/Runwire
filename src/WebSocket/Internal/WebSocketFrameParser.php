<?php

declare(strict_types=1);

namespace Infocyph\Runwire\WebSocket\Internal;

use Infocyph\Runwire\Network\Internal\ByteBudget;
use Infocyph\Runwire\WebSocket\WebSocketOptions;
use OverflowException;

/**
 * Incrementally parses bounded client-to-server WebSocket frames.
 *
 * @internal
 */
final class WebSocketFrameParser
{
    private int $budgetBytes = 0;

    private string $buffer = '';

    /**
     * Create a bounded parser optionally charged to the worker byte budget.
     */
    public function __construct(
        private WebSocketOptions $options,
        private ?ByteBudget $workerBudget = null,
    ) {}

    /**
     * Release any still-buffered bytes from the worker budget.
     */
    public function __destruct()
    {
        $this->release($this->workerBudgetBytes);
    }

    /**
     * Append transport bytes while enforcing the parser and worker-wide byte ceilings.
     */
    public function append(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        $length = strlen($bytes);
        if (strlen($this->buffer) + $length > $this->options->maxBufferedBytes) {
            throw new WebSocketProtocolException(1009, 'WebSocket parser buffer limit exceeded.');
        }
        if ($this->workerBudget !== null && !$this->workerBudget->reserve($length)) {
            throw new WebSocketProtocolException(1009, 'WebSocket worker byte budget is exhausted.');
        }

        $this->workerBudgetBytes += $length;
        $this->buffer .= $bytes;
    }

    /**
     * Return currently buffered wire bytes.
     */
    public function bufferedBytes(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Parse up to the requested number of complete frames.
     *
     * @return list<WebSocketFrame>
     */
    public function parse(int $maxFrames): array
    {
        if ($maxFrames < 1) {
            throw new OverflowException('WebSocket parser frame budget must be positive.');
        }

        $frames = [];
        while (count($frames) < $maxFrames) {
            $frame = $this->extract();
            if ($frame === null) {
                break;
            }

            $frames[] = $frame;
        }

        return $frames;
    }

    private static function uint32(string $bytes): int
    {
        return (ord($bytes[0]) << 24)
            | (ord($bytes[1]) << 16)
            | (ord($bytes[2]) << 8)
            | ord($bytes[3]);
    }

    private static function unmask(string $payload, string $mask): string
    {
        $length = strlen($payload);
        for ($index = 0; $index < $length; ++$index) {
            $payload[$index] = $payload[$index] ^ $mask[$index & 3];
        }

        return $payload;
    }

    private function consume(int $bytes): string
    {
        $data = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);
        $this->workerBudgetBytes -= $bytes;
        $this->release($bytes);

        return $data;
    }

    private function decodeLength(int $indicator, int &$offset): ?int
    {
        if ($indicator < 126) {
            return $indicator;
        }
        if ($indicator === 126) {
            if (strlen($this->buffer) < 4) {
                return null;
            }

            $length = (ord($this->buffer[2]) << 8) | ord($this->buffer[3]);
            if ($length < 126) {
                throw new WebSocketProtocolException(1002, 'WebSocket frame uses a non-minimal 16-bit length.');
            }
            $offset = 4;

            return $length;
        }

        if (strlen($this->buffer) < 10) {
            return null;
        }

        $highValue = self::uint32(substr($this->buffer, 2, 4));
        $lowValue = self::uint32(substr($this->buffer, 6, 4));
        if (($highValue & 0x80000000) !== 0) {
            throw new WebSocketProtocolException(1002, 'WebSocket frame length has the reserved high bit set.');
        }
        if ($highValue !== 0 || $lowValue < 65_536) {
            throw new WebSocketProtocolException(
                $highValue === 0 ? 1002 : 1009,
                $highValue === 0
                    ? 'WebSocket frame uses a non-minimal 64-bit length.'
                    : 'WebSocket frame exceeds the configured payload ceiling.',
            );
        }
        $offset = 10;

        return $lowValue;
    }

    private function extract(): ?WebSocketFrame
    {
        if (strlen($this->buffer) < 2) {
            return null;
        }

        $first = ord($this->buffer[0]);
        $second = ord($this->buffer[1]);
        $fin = ($first & 0x80) !== 0;
        $rsv = $first & 0x70;
        $opcode = $first & 0x0f;
        $masked = ($second & 0x80) !== 0;
        $indicator = $second & 0x7f;

        if ($rsv !== 0) {
            throw new WebSocketProtocolException(1002, 'WebSocket RSV bits require a negotiated extension.');
        }
        if (!in_array($opcode, [0x0, 0x1, 0x2, 0x8, 0x9, 0xA], true)) {
            throw new WebSocketProtocolException(1002, 'WebSocket frame opcode is reserved or unsupported.');
        }
        if (!$masked) {
            throw new WebSocketProtocolException(1002, 'Client WebSocket frames must be masked.');
        }

        $offset = 2;
        $length = $this->decodeLength($indicator, $offset);
        if ($length === null) {
            return null;
        }
        if ($length > $this->options->maxFramePayloadBytes) {
            throw new WebSocketProtocolException(1009, 'WebSocket frame payload exceeds the configured limit.');
        }
        if ($opcode >= 0x8 && (!$fin || $length > 125)) {
            throw new WebSocketProtocolException(1002, 'WebSocket control frames must be final and at most 125 bytes.');
        }

        $frameBytes = $offset + 4 + $length;
        if (strlen($this->buffer) < $frameBytes) {
            return null;
        }

        $wire = $this->consume($frameBytes);
        $mask = substr($wire, $offset, 4);
        $payload = substr($wire, $offset + 4, $length);

        return new WebSocketFrame($fin, $opcode, self::unmask($payload, $mask));
    }

    private function release(int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $this->workerBudget?->release($bytes);
    }
}
