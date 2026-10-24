<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Internal;

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\VarIntCodec;

/**
 * Parses and tracks the type prefix of a peer HTTP/3 unidirectional stream.
 */
final class PeerUnidirectionalStream
{
    private bool $claimed = false;

    private string $prefix = '';

    private ?int $type = null;

    /**
     * Create state for a client-initiated unidirectional stream.
     */
    public function __construct(private readonly int $streamId)
    {
        if ($streamId < 0 || ($streamId & 0x03) !== 0x02) {
            throw new \InvalidArgumentException('HTTP/3 peer unidirectional stream ID must be client-initiated and unidirectional.');
        }
    }

    /**
     * Mark the decoded stream type as claimed by connection state.
     */
    public function claim(): void
    {
        if ($this->type === null) {
            throw new \LogicException('HTTP/3 unidirectional stream type is not available yet.');
        }

        $this->claimed = true;
    }

    /**
     * Determine whether the stream type has been claimed.
     */
    public function claimed(): bool
    {
        return $this->claimed;
    }

    /**
     * Feed bytes and return payload bytes following the stream-type prefix.
     */
    public function push(string $bytes): string
    {
        if ($this->type !== null) {
            return $bytes;
        }

        $this->prefix .= $bytes;
        $decoded = VarIntCodec::tryDecode($this->prefix);
        if ($decoded === null) {
            if (strlen($this->prefix) > 8) {
                throw new Http3Exception(ErrorCode::STREAM_CREATION_ERROR, 'Invalid HTTP/3 unidirectional stream type.');
            }

            return '';
        }

        [$this->type, $offset] = $decoded;
        $payload = substr($this->prefix, $offset);
        $this->prefix = '';

        return $payload;
    }

    /**
     * Return the QUIC stream identifier.
     */
    public function streamId(): int
    {
        return $this->streamId;
    }

    /**
     * Return the decoded HTTP/3 stream type identifier when available.
     */
    public function type(): ?int
    {
        return $this->type;
    }
}
