<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

use Infocyph\Runwire\Network\Connection;

final class Http1Input
{
    private string $buffer = '';

    public function __construct(private readonly Connection $connection) {}

    public function availableBytes(): int
    {
        return strlen($this->buffer) + $this->connection->receivedBytes();
    }

    public function bufferedBytes(): int
    {
        return strlen($this->buffer);
    }

    public function readLine(int $maxBytes, int $tooLongStatus): ?string
    {
        while (($position = strpos($this->buffer, "\r\n")) === false) {
            if (strlen($this->buffer) > $maxBytes) {
                throw new ParseFailure($tooLongStatus, 'HTTP line exceeds configured limit.');
            }
            $remaining = $maxBytes + 2 - strlen($this->buffer);
            if ($remaining <= 0 || $this->connection->receivedBytes() === 0) {
                return null;
            }
            $chunk = $this->connection->read(min(4_096, $remaining));
            if ($chunk === '') {
                return null;
            }
            $this->buffer .= $chunk;
        }

        if ($position > $maxBytes) {
            throw new ParseFailure($tooLongStatus, 'HTTP line exceeds configured limit.');
        }
        $line = substr($this->buffer, 0, $position);
        $this->buffer = substr($this->buffer, $position + 2);

        return $line;
    }

    public function take(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        $fromBuffer = min($bytes, strlen($this->buffer));
        $data = $fromBuffer > 0 ? substr($this->buffer, 0, $fromBuffer) : '';
        if ($fromBuffer > 0) {
            $this->buffer = substr($this->buffer, $fromBuffer);
        }
        $remaining = $bytes - $fromBuffer;
        if ($remaining > 0) {
            $data .= $this->connection->read($remaining);
        }

        return $data;
    }
}
