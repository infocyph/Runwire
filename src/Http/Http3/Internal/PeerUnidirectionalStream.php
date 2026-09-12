<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Internal;

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\VarIntCodec;

final class PeerUnidirectionalStream
{
    private bool $claimed = false;

    private string $prefix = '';

    private ?int $type = null;

    public function __construct(private readonly int $streamId)
    {
        if ($streamId < 0 || ($streamId & 0x03) !== 0x02) {
            throw new \InvalidArgumentException('HTTP/3 peer unidirectional stream ID must be client-initiated and unidirectional.');
        }
    }

    public function claim(): void
    {
        if ($this->type === null) {
            throw new \LogicException('HTTP/3 unidirectional stream type is not available yet.');
        }

        $this->claimed = true;
    }

    public function claimed(): bool
    {
        return $this->claimed;
    }

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

    public function streamId(): int
    {
        return $this->streamId;
    }

    public function type(): ?int
    {
        return $this->type;
    }
}
