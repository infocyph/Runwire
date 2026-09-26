<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http1\Internal;

use Infocyph\Runwire\Network\Connection;
use Infocyph\Runwire\Network\Internal\ByteQueue;

/**
 * Buffers and slices HTTP/1.1 bytes from a network connection.
 */
final readonly class Http1Input
{
    private ByteQueue $buffer;

    /**
     * Create an input reader for the supplied connection.
     */
    public function __construct(private readonly Connection $connection)
    {
        $this->buffer = new ByteQueue($connection->bufferBudget());
    }

    /**
     * Return all currently available buffered and connection bytes.
     */
    public function availableBytes(): int
    {
        return $this->buffer->bytes() + $this->connection->receivedBytes();
    }

    /**
     * Return the number of bytes retained in the local line buffer.
     */
    public function bufferedBytes(): int
    {
        return $this->buffer->bytes();
    }

    /**
     * Release bytes retained by the HTTP input owner.
     */
    public function clear(): void
    {
        $this->buffer->clear();
    }

    /**
     * Read one CRLF-terminated line within the configured limit.
     */
    public function readLine(int $maxBytes, int $tooLongStatus): ?string
    {
        while (true) {
            $buffer = $this->buffer->peek();
            $position = strpos($buffer, "\r\n");
            if ($position !== false) {
                if ($position > $maxBytes) {
                    throw new ParseFailure($tooLongStatus, 'HTTP line exceeds configured limit.');
                }

                $line = substr($buffer, 0, $position);
                $this->buffer->discard($position + 2);

                return $line;
            }

            $buffered = $this->buffer->bytes();
            if ($buffered > $maxBytes) {
                throw new ParseFailure($tooLongStatus, 'HTTP line exceeds configured limit.');
            }

            $remaining = $maxBytes + 2 - $buffered;
            if ($remaining <= 0 || $this->connection->receivedBytes() === 0) {
                return null;
            }

            $chunk = $this->connection->read(min(4_096, $remaining));
            if ($chunk === '') {
                return null;
            }

            // Connection::read() released this exact reservation from the shared
            // worker budget; the input queue immediately assumes ownership.
            $this->buffer->append($chunk);
        }
    }

    /**
     * Consume up to the requested number of bytes.
     */
    public function take(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }

        $fromBuffer = min($bytes, $this->buffer->bytes());
        $data = $fromBuffer > 0 ? $this->buffer->read($fromBuffer) : '';
        $remaining = $bytes - $fromBuffer;
        if ($remaining > 0) {
            $data .= $this->connection->read($remaining);
        }

        return $data;
    }
}
