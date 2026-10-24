<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Infocyph\Runwire\Http\Http2\Enum\FrameType;
use Infocyph\Runwire\Http\Http2\Frame;
use Infocyph\Runwire\Http\Http2\FrameWriter;
use Infocyph\Runwire\Http\Http2\Hpack\Encoder;
use Infocyph\Runwire\Http\Http2\Http2Connection;
use Infocyph\Runwire\Http\Http2\PeerSettings;
use RuntimeException;

/**
 * @internal Minimal H2 outbound preface, peer SETTINGS and HEADERS bootstrap.
 *
 * This is not a multiplexed HTTP/2 client: the caller still needs verified
 * ALPN, flow control, CONTINUATION, stream lifecycle and GOAWAY ownership.
 */
final class Http2ClientHandshake
{
    private readonly Encoder $encoder;

    private readonly PeerSettings $peerSettings;

    private bool $closed = false;

    private bool $receivedSettings = false;

    private bool $requestSent = false;

    private bool $started = false;

    /** Prepare one new connection-scoped outbound HTTP/2 handshake owner. */
    public function __construct()
    {
        $this->encoder = new Encoder();
        $this->peerSettings = new PeerSettings();
    }

    /** Encode one conservative bodyless GET/HEAD header block for stream one. */
    public function firstRequest(string $method, string $scheme, string $authority, string $path): string
    {
        if (!$this->receivedSettings || $this->closed || $this->requestSent
            || !in_array($method, ['GET', 'HEAD'], true)
            || $scheme !== 'https' || $authority === '' || $path === ''
            || !str_starts_with($path, '/')
            || str_contains($authority, '@') || str_contains($authority, '/')
            || preg_match('/[\x00-\x20\x7F]/', $authority . $path) === 1) {
            throw new RuntimeException('HTTP/2 request must be a valid bodyless HTTPS request on a verified connection.');
        }

        $block = $this->encoder->encode([
            [':method', $method],
            [':scheme', $scheme],
            [':authority', $authority],
            [':path', $path],
        ]);
        if (strlen($block) > min($this->peerSettings->maxFrameSize, 16_384)) {
            throw new RuntimeException('HTTP/2 request HEADERS exceeds the supported single-frame bound.');
        }

        $this->requestSent = true;

        return FrameWriter::encode(new Frame(FrameType::HEADERS->value, 0x5, 1, $block));
    }

    /** Report whether peer SETTINGS were validated before request dispatch. */
    public function ready(): bool
    {
        return $this->receivedSettings && !$this->closed;
    }

    /** Accept only bounded connection control frames and return wire ACK, if any. */
    public function receive(Frame $frame): string
    {
        if (!$this->started || $this->closed) {
            throw new RuntimeException('HTTP/2 client handshake is not active.');
        }
        if (!$this->receivedSettings && ($frame->knownType() !== FrameType::SETTINGS || $frame->hasFlag(0x1))) {
            throw new RuntimeException('HTTP/2 server must send SETTINGS as its first frame.');
        }

        return match ($frame->knownType()) {
            FrameType::SETTINGS => $this->settings($frame),
            FrameType::PING => $this->ping($frame),
            FrameType::GOAWAY => $this->goaway($frame),
            default => throw new RuntimeException('HTTP/2 bootstrap accepts connection control frames only.'),
        };
    }

    /** Return the exact H2 client connection preface and first SETTINGS. */
    public function start(): string
    {
        if ($this->started) {
            throw new RuntimeException('HTTP/2 client preface may be emitted once only.');
        }
        $this->started = true;

        return Http2Connection::CLIENT_PREFACE
            . FrameWriter::encode(FrameWriter::settings([PeerSettings::ENABLE_PUSH => 0]));
    }

    private function goaway(Frame $frame): string
    {
        if ($frame->streamId !== 0 || strlen($frame->payload) < 8) {
            throw new RuntimeException('HTTP/2 GOAWAY must contain the last stream and error.');
        }
        $this->closed = true;

        return '';
    }

    private function ping(Frame $frame): string
    {
        if ($frame->streamId !== 0 || strlen($frame->payload) !== 8) {
            throw new RuntimeException('HTTP/2 PING must carry exactly eight bytes on stream zero.');
        }

        return $frame->hasFlag(0x1)
            ? ''
            : FrameWriter::encode(new Frame(FrameType::PING->value, 0x1, 0, $frame->payload));
    }

    private function settings(Frame $frame): string
    {
        if ($frame->streamId !== 0 || ($frame->hasFlag(0x1) && $frame->payload !== '')) {
            throw new RuntimeException('HTTP/2 SETTINGS ACK must be empty on stream zero.');
        }
        if ($frame->hasFlag(0x1)) {
            return '';
        }
        if (strlen($frame->payload) % 6 !== 0) {
            throw new RuntimeException('HTTP/2 SETTINGS payload is not a multiple of six.');
        }
        for ($offset = 0; $offset < strlen($frame->payload); $offset += 6) {
            $identifier = unpack('n', substr($frame->payload, $offset, 2));
            if ($identifier === false) {
                throw new RuntimeException('Unable to decode HTTP/2 setting identifier.');
            }
            if ($identifier[1] === PeerSettings::ENABLE_PUSH) {
                throw new RuntimeException('HTTP/2 server must not send ENABLE_PUSH.');
            }
        }
        $this->peerSettings->apply($frame->payload);
        $this->encoder->setPeerMaxDynamicTableBytes(min(4_096, $this->peerSettings->headerTableSize));
        $this->receivedSettings = true;

        return FrameWriter::encode(FrameWriter::settings([], true));
    }
}
