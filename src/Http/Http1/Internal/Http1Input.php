<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

use Infocyph\Runwire\Network\Connection;

/**
 * Buffers and slices HTTP/1.1 bytes from a network connection.
 */
final class Http1Input
{
    private string $buffer = '';

    /**
     * Create an input reader for the supplied connection.
     */
    public function __construct(private readonly Connection $connection) {}

    /**
     * Return all currently available buffered and connection bytes.
     */
    public function availableBytes(): int
    {
        return strlen($this->buffer) + $this->connection->receivedBytes();
    }

    /**
     * Return the number of bytes retained in the local line buffer.
     */
    public function bufferedBytes(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Read one CRLF-terminated line within the configured limit.
     */
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

    /**
     * Consume up to the requested number of bytes.
     */
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
