<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Infocyph\Runwire\Http\Http3\ControlStream;
use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Enum\SettingIdentifier;
use Infocyph\Runwire\Http\Http3\Enum\StreamType;
use Infocyph\Runwire\Http\Http3\Frame;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Settings;
use Infocyph\Runwire\Http\Http3\VarIntCodec;
use LogicException;

/**
 * @internal Owns one outbound HTTP/3 control-stream validation state.
 *
 * QUIC sessions and I/O remain off-loop inside a dedicated worker. Outgoing
 * QPACK dynamic references are disabled until full stream ownership exists.
 */
final class Http3ClientControl
{
    private readonly ControlStream $peer;

    private bool $localStarted = false;

    private ?int $peerGoawayId = null;

    private string $peerPrefix = '';

    private bool $peerTyped = false;

    /** Build an incremental peer control parser with bounded payloads. */
    public function __construct(int $maxFramePayloadBytes = 65_536, private readonly int $decoderCapacity = 0, private readonly int $maxBlocked = 0)
    {
        $this->peer = new ControlStream($maxFramePayloadBytes);
    }

    /** Fail if the peer closes a mandatory HTTP/3 control stream. */
    public function closePeerControl(): never
    {
        $this->peer->close();
    }

    /** Announce a local QPACK critical stream only after sending HTTP/3 SETTINGS. */
    public function localQpackPreamble(StreamType $type): string
    {
        if (!$this->localStarted) {
            throw new LogicException('HTTP/3 control stream must start before local QPACK streams.');
        }
        if (!in_array($type, [StreamType::QPACK_ENCODER, StreamType::QPACK_DECODER], true)) {
            throw new LogicException('Only HTTP/3 QPACK encoder and decoder streams are permitted.');
        }

        return VarIntCodec::encode($type->value);
    }

    /** The most restrictive client-bidirectional request ID refused by peer GOAWAY. */
    public function peerGoaway(): ?int
    {
        return $this->peerGoawayId;
    }

    /** Return peer SETTINGS only once its complete first frame was parsed. */
    public function peerSettings(): ?Settings
    {
        return $this->peer->peerSettings();
    }

    /**
     * Parse a fragmented peer stream type and control frames.
     *
     * @return list<Frame>
     */
    public function receivePeerControl(string $bytes): array
    {
        if (!$this->localStarted) {
            throw new LogicException('HTTP/3 client control stream must start before peer traffic.');
        }

        if (!$this->peerTyped) {
            $prefixLength = strlen($this->peerPrefix);
            $probe = $this->peerPrefix . substr($bytes, 0, 8 - $prefixLength);
            $type = VarIntCodec::tryDecode($probe);
            if ($type === null) {
                $this->peerPrefix = $probe;

                return [];
            }

            [$identifier, $offset] = $type;
            if ($identifier !== StreamType::CONTROL->value) {
                throw new Http3Exception(ErrorCode::STREAM_CREATION_ERROR, 'Peer HTTP/3 control stream has the wrong stream type.');
            }
            $this->peerTyped = true;
            $this->peerPrefix = '';
            $bytes = substr($bytes, $offset - $prefixLength);
        }

        $frames = $this->peer->push($bytes);
        foreach ($frames as $frame) {
            if ($frame->knownType() === \Infocyph\Runwire\Http\Http3\Enum\FrameType::GOAWAY) {
                $this->validateGoaway($frame);
            }
        }

        return $frames;
    }

    /** Emit local HTTP/3 stream type and SETTINGS, exactly once. */
    public function start(): string
    {
        if ($this->localStarted) {
            throw new LogicException('HTTP/3 local control stream was already started.');
        }
        $this->localStarted = true;

        return ControlStream::preamble(new Settings([
            SettingIdentifier::QPACK_MAX_TABLE_CAPACITY->value => $this->decoderCapacity,
            SettingIdentifier::QPACK_BLOCKED_STREAMS->value => $this->maxBlocked,
            SettingIdentifier::MAX_FIELD_SECTION_SIZE->value => 65_536,
        ]));
    }

    /** Accept only a complete, nonincreasing client-bidi GOAWAY request stream ID. */
    private function validateGoaway(Frame $frame): void
    {
        $decoded = VarIntCodec::tryDecode($frame->payload);
        if ($decoded === null || $decoded[1] !== strlen($frame->payload) || $decoded[0] % 4 !== 0
            || ($this->peerGoawayId !== null && $decoded[0] > $this->peerGoawayId)) {
            throw new Http3Exception(ErrorCode::ID_ERROR, 'HTTP/3 peer GOAWAY stream ID is invalid or increasing.');
        }
        $this->peerGoawayId = $decoded[0];
    }

}
