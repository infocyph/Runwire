<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2\Internal;

use Infocyph\Runwire\Http\Http2\ErrorCode;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameWriter;

final class FlowController
{
    private int $connectionSendWindow = 65_535;
    private int $connectionReceiveWindow = 65_535;

    public function availableSend(Http2Stream $stream): int
    {
        return max(0, min($this->connectionSendWindow, $stream->sendWindow));
    }

    public function consumeSend(Http2Stream $stream, int $bytes): void
    {
        if ($bytes < 0 || $bytes > $this->availableSend($stream)) {
            throw new \LogicException('HTTP/2 outbound flow-control accounting underflow.');
        }
        $this->connectionSendWindow -= $bytes;
        $stream->sendWindow -= $bytes;
    }

    public function consumeConnectionReceive(int $bytes): void
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('HTTP/2 inbound flow-control bytes cannot be negative.');
        }
        $this->connectionReceiveWindow -= $bytes;
        if ($this->connectionReceiveWindow < 0) {
            throw new ConnectionError(ErrorCode::FLOW_CONTROL_ERROR, 'HTTP/2 connection receive window was exceeded.');
        }
    }

    public function consumeReceive(Http2Stream $stream, int $bytes): void
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('HTTP/2 inbound flow-control bytes cannot be negative.');
        }
        $this->consumeConnectionReceive($bytes);
        $stream->receiveWindow -= $bytes;
        if ($stream->receiveWindow < 0) {
            throw new StreamError($stream->id, ErrorCode::FLOW_CONTROL_ERROR, 'HTTP/2 stream receive window was exceeded.');
        }
    }

    /** @return list<Frame> */
    public function creditReceive(?Http2Stream $stream, int $bytes): array
    {
        if ($bytes <= 0) {
            return [];
        }
        if ($bytes > 0x7FFF_FFFF - $this->connectionReceiveWindow) {
            throw new ConnectionError(ErrorCode::FLOW_CONTROL_ERROR, 'HTTP/2 connection receive window credit overflow.');
        }
        $this->connectionReceiveWindow += $bytes;
        $frames = [FrameWriter::windowUpdate(0, $bytes)];
        if ($stream !== null && $stream->state !== \Infocyph\Runwire\Http\Http2\StreamState::CLOSED) {
            if ($bytes > 0x7FFF_FFFF - $stream->receiveWindow) {
                throw new StreamError($stream->id, ErrorCode::FLOW_CONTROL_ERROR, 'HTTP/2 stream receive window credit overflow.');
            }
            $stream->receiveWindow += $bytes;
            $frames[] = FrameWriter::windowUpdate($stream->id, $bytes);
        }
        return $frames;
    }

    public function updateConnectionSend(int $increment): void
    {
        if ($increment <= 0) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'HTTP/2 connection WINDOW_UPDATE increment cannot be zero.');
        }
        if ($increment > 0x7FFF_FFFF - $this->connectionSendWindow) {
            throw new ConnectionError(ErrorCode::FLOW_CONTROL_ERROR, 'HTTP/2 connection send window overflow.');
        }
        $this->connectionSendWindow += $increment;
    }

    public function updateStreamSend(Http2Stream $stream, int $increment): void
    {
        if ($increment <= 0) {
            throw new StreamError($stream->id, ErrorCode::PROTOCOL_ERROR, 'HTTP/2 stream WINDOW_UPDATE increment cannot be zero.');
        }
        if ($increment > 0x7FFF_FFFF - $stream->sendWindow) {
            throw new StreamError($stream->id, ErrorCode::FLOW_CONTROL_ERROR, 'HTTP/2 stream send window overflow.');
        }
        $stream->sendWindow += $increment;
    }

    /** @param array<int, Http2Stream> $streams */
    public function applyInitialWindowDelta(array $streams, int $delta): void
    {
        foreach ($streams as $stream) {
            if ($stream->state === \Infocyph\Runwire\Http\Http2\StreamState::CLOSED) {
                continue;
            }
            $next = $stream->sendWindow + $delta;
            if ($next > 0x7FFF_FFFF || $next < -0x7FFF_FFFF) {
                throw new ConnectionError(ErrorCode::FLOW_CONTROL_ERROR, 'HTTP/2 stream send window overflow after SETTINGS update.');
            }
            $stream->sendWindow = $next;
        }
    }
}
