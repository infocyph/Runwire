<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client\Internal;

use Infocyph\Runwire\Http\Headers;
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

    private int $lastIssuedStreamId = 0;

    private ?int $peerGoAwayErrorCode = null;

    private ?int $peerGoAwayLastStreamId = null;

    private bool $receivedSettings = false;

    private bool $requestSent = false;

    private bool $started = false;

    /** Prepare one new connection-scoped outbound HTTP/2 handshake owner. */
    public function __construct()
    {
        $this->encoder = new Encoder();
        $this->peerSettings = new PeerSettings();
    }

    /** Preserve the initial one-shot request contract for the existing client. */
    public function firstRequest(string $method, string $scheme, string $authority, string $path, ?Headers $headers = null): string
    {
        if ($this->requestSent) {
            throw new RuntimeException('HTTP/2 request must be a valid bodyless HTTPS request on a verified connection.');
        }
        $wire = $this->requestOnStream(1, $method, $scheme, $authority, $path, $headers);
        $this->requestSent = true;

        return $wire;
    }

    /** Return the peer's terminal GOAWAY error code without exposing debug bytes. */
    public function peerGoAwayErrorCode(): ?int
    {
        return $this->peerGoAwayErrorCode;
    }

    /** Expose the last stream ID reported by the peer's terminal GOAWAY. */
    public function peerGoAwayLastStreamId(): ?int
    {
        return $this->peerGoAwayLastStreamId;
    }

    /** Maximum concurrent stream slots advertised by the peer; null means unspecified. */
    public function peerMaxConcurrentStreams(): ?int
    {
        return $this->peerSettings->maxConcurrentStreams;
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
            FrameType::WINDOW_UPDATE => $this->windowUpdate($frame),
            null => '', // RFC 9113: ignore unrecognized extension frame types.
            default => throw new RuntimeException('HTTP/2 bootstrap accepts connection control frames only.'),
        };
    }

    /**
     * Encode one conservative request on a strictly increasing odd client ID.
     * The connected transport owner must still enforce active stream slots.
     */
    public function requestOnStream(
        int $streamId,
        string $method,
        string $scheme,
        string $authority,
        string $path,
        ?Headers $headers = null,
    ): string {
        if (!$this->receivedSettings || $this->closed
            || $streamId < 1 || $streamId > 0x7FFF_FFFF
            || $streamId % 2 === 0 || $streamId <= $this->lastIssuedStreamId
            || !in_array($method, ['GET', 'HEAD'], true)
            || $scheme !== 'https' || $authority === '' || $path === ''
            || !str_starts_with($path, '/')
            || str_contains($authority, '@') || str_contains($authority, '/')
            || preg_match('/[\\x00-\\x20\\x7F]/', $authority . $path) === 1) {
            throw new RuntimeException('HTTP/2 request must be a valid bodyless HTTPS request on a verified connection.');
        }

        $block = $this->encoder->encode($this->requestHeaders($method, $scheme, $authority, $path, $headers));
        if (strlen($block) > min($this->peerSettings->maxFrameSize, 16_384)) {
            throw new RuntimeException('HTTP/2 request HEADERS exceeds the supported single-frame bound.');
        }
        $this->lastIssuedStreamId = $streamId;

        return FrameWriter::encode(new Frame(FrameType::HEADERS->value, 0x5, $streamId, $block));
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
        if ($frame->streamId !== 0 || $frame->flags !== 0 || strlen($frame->payload) < 8) {
            throw new RuntimeException('HTTP/2 GOAWAY must contain the last stream and error.');
        }
        $last = unpack('Nlast', substr($frame->payload, 0, 4));
        $error = unpack('Nerror', substr($frame->payload, 4, 4));
        if (!is_int($last['last'] ?? null) || !is_int($error['error'] ?? null)) {
            throw new RuntimeException('HTTP/2 GOAWAY has invalid stream or error fields.');
        }
        $this->peerGoAwayLastStreamId = $last['last'] & 0x7FFF_FFFF;
        $this->peerGoAwayErrorCode = $error['error'];
        $this->closed = true;

        return '';
    }

    private function ping(Frame $frame): string
    {
        if ($frame->streamId !== 0 || ($frame->flags & ~0x1) !== 0 || strlen($frame->payload) !== 8) {
            throw new RuntimeException('HTTP/2 PING must carry exactly eight bytes on stream zero.');
        }

        return $frame->hasFlag(0x1)
            ? ''
            : FrameWriter::encode(new Frame(FrameType::PING->value, 0x1, 0, $frame->payload));
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private function requestHeaders(string $method, string $scheme, string $authority, string $path, ?Headers $headers): array
    {
        $fields = [
            [':method', $method],
            [':scheme', $scheme],
            [':authority', $authority],
            [':path', $path],
        ];
        foreach ($headers?->fields() ?? [] as $field) {
            if (in_array($field->name, [
                'host', 'connection', 'keep-alive', 'proxy-connection',
                'transfer-encoding', 'upgrade', 'te',
            ], true)) {
                throw new RuntimeException('HTTP/2 request has forbidden connection-specific headers.');
            }
            $fields[] = [$field->name, $field->value];
        }

        return $fields;
    }

    private function settings(Frame $frame): string
    {
        if ($frame->streamId !== 0 || ($frame->flags & ~0x1) !== 0
            || ($frame->hasFlag(0x1) && $frame->payload !== '')) {
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

    private function windowUpdate(Frame $frame): string
    {
        if ($frame->streamId !== 0 || $frame->flags !== 0 || strlen($frame->payload) !== 4) {
            throw new RuntimeException('HTTP/2 connection WINDOW_UPDATE must contain four bytes on stream zero.');
        }
        $decoded = unpack('Nincrement', $frame->payload);
        $increment = $decoded['increment'] ?? null;
        if (!is_int($increment) || ($increment & 0x7FFF_FFFF) === 0) {
            throw new RuntimeException('HTTP/2 connection WINDOW_UPDATE increment cannot be zero.');
        }

        // H2 HEAD/GET bootstrap has no outbound body to consume credit yet.
        return '';
    }
}
