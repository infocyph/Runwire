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

    private string $peerPrefix = '';

    private bool $localStarted = false;

    private bool $peerTyped = false;

    /** Build an incremental peer control parser with bounded payloads. */
    public function __construct(int $maxFramePayloadBytes = 65_536)
    {
        $this->peer = new ControlStream($maxFramePayloadBytes);
    }

    /** Fail if the peer closes a mandatory HTTP/3 control stream. */
    public function closePeerControl(): never
    {
        $this->peer->close();
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

        return $this->peer->push($bytes);
    }

    /** Emit local HTTP/3 stream type and SETTINGS, exactly once. */
    public function start(): string
    {
        if ($this->localStarted) {
            throw new LogicException('HTTP/3 local control stream was already started.');
        }
        $this->localStarted = true;

        return ControlStream::preamble(new Settings([
            SettingIdentifier::QPACK_MAX_TABLE_CAPACITY->value => 0,
            SettingIdentifier::QPACK_BLOCKED_STREAMS->value => 0,
            SettingIdentifier::MAX_FIELD_SECTION_SIZE->value => 65_536,
        ]));
    }
}
