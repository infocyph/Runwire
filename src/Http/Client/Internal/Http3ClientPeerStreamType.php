<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Infocyph\Runwire\Http\Http3\VarIntCodec;

/** @internal Decode one fragmented QUIC unidirectional stream type without byte peeking. */
final class Http3ClientPeerStreamType
{
    private string $prefix = '';

    /**
     * @return array{type:int, payload:string, wire:string}|null
     */
    public function push(string $bytes): ?array
    {
        $probe = $this->prefix . substr($bytes, 0, 8 - strlen($this->prefix));
        $decoded = VarIntCodec::tryDecode($probe);
        if ($decoded === null) {
            $this->prefix = $probe;

            return null;
        }

        [$type, $offset] = $decoded;
        $wire = $this->prefix . $bytes;
        $this->prefix = '';

        return [
            'type' => $type,
            'payload' => substr($wire, $offset),
            'wire' => $wire,
        ];
    }
}
