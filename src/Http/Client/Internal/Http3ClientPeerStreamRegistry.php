<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Enum\StreamType;
use Infocyph\Runwire\Http\Http3\Http3Exception;

/** @internal Owns one bounded classification per peer-created unidirectional QUIC stream. */
final class Http3ClientPeerStreamRegistry
{
    /** @var array<int, true> */
    private array $critical = [];

    /** @var array<int, Http3ClientPeerStreamType> */
    private array $pending = [];

    /** @var array<int, int> */
    private array $types = [];

    /**
     * Classify one stream without assuming a one-byte QUIC varint.
     *
     * @return array{type:int,payload:string,wire:string,initial:bool}|null
     */
    public function push(int $stream, string $bytes, bool $eof): ?array
    {
        if (isset($this->types[$stream])) {
            return ['type' => $this->types[$stream], 'payload' => $bytes, 'wire' => $bytes, 'initial' => false];
        }

        $probe = ($this->pending[$stream] ??= new Http3ClientPeerStreamType())->push($bytes);
        if ($probe === null) {
            if ($eof) {
                throw new Http3Exception(ErrorCode::STREAM_CREATION_ERROR, 'Peer HTTP/3 stream ended before its type was complete.');
            }

            return null;
        }

        unset($this->pending[$stream]);
        $this->assertUniqueCritical($probe['type']);
        $this->types[$stream] = $probe['type'];

        return $probe + ['initial' => true];
    }

    private function assertUniqueCritical(int $type): void
    {
        if (!in_array($type, [
            StreamType::CONTROL->value,
            StreamType::QPACK_ENCODER->value,
            StreamType::QPACK_DECODER->value,
        ], true)) {
            return;
        }

        if (isset($this->critical[$type])) {
            throw new Http3Exception(ErrorCode::STREAM_CREATION_ERROR, 'Duplicate HTTP/3 peer critical stream.');
        }
        $this->critical[$type] = true;
    }
}
