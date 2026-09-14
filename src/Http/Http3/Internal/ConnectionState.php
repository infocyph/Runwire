<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Internal;

use Infocyph\Runwire\Http\Http3\ControlStream;
use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Enum\StreamType;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Qpack\Decoder;
use Infocyph\Runwire\Http\Http3\Qpack\Encoder;
use Infocyph\Runwire\Http\Http3\Settings;
use Infocyph\Runwire\Http\Http3\VarIntCodec;

/**
 * Owns HTTP/3 connection-level stream, SETTINGS, and QPACK state.
 */
final class ConnectionState
{
    private readonly Decoder $decoder;

    private readonly Settings $localSettings;

    private readonly ControlStream $peerControl;

    /** @var array<int, int> */
    private array $criticalPeerStreams = [];

    private ?Encoder $encoder = null;

    /** @var array<int, PeerUnidirectionalStream> */
    private array $peerUnidirectionalStreams = [];

    private int $peerUnidirectionalStreamsCreated = 0;

    private string $pendingPeerDecoderInstructions = '';

    /** @var array<int, RequestStream> */
    private array $requestStreams = [];

    private int $requestStreamsCreated = 0;

    /**
     * Create connection state with bounded HTTP/3 and QPACK resources.
     */
    public function __construct(private readonly Http3Limits $limits = new Http3Limits())
    {
        $this->localSettings = Settings::serverDefaults($limits);
        $this->peerControl = new ControlStream($limits->maxFramePayloadBytes);
        $this->decoder = new Decoder(
            $limits->qpackMaxTableCapacity,
            $limits->qpackMaxBlockedStreams,
            $limits->maxFieldSectionBytes,
            $limits->maxHeaderFields,
            $limits->maxBlockedFieldSectionBytes,
        );
    }

    /**
     * Cancel and discard a tracked request stream.
     */
    public function cancelRequestStream(int $streamId): void
    {
        $stream = $this->requestStreams[$streamId] ?? null;
        if ($stream === null) {
            return;
        }

        $stream->cancel();
        unset($this->requestStreams[$streamId]);
    }

    /**
     * Finish a peer unidirectional stream while enforcing critical-stream lifetime rules.
     */
    public function finishPeerUnidirectional(int $streamId): void
    {
        $stream = $this->peerUnidirectionalStreams[$streamId] ?? null;
        if ($stream === null) {
            return;
        }

        $typeValue = $stream->type();
        if ($typeValue === null) {
            unset($this->peerUnidirectionalStreams[$streamId]);

            return;
        }

        $type = StreamType::tryFrom($typeValue);
        if (in_array($type, [StreamType::CONTROL, StreamType::QPACK_ENCODER, StreamType::QPACK_DECODER], true)) {
            throw new Http3Exception(
                ErrorCode::CLOSED_CRITICAL_STREAM,
                sprintf('HTTP/3 critical peer stream 0x%x was closed.', $typeValue),
            );
        }

        unset($this->peerUnidirectionalStreams[$streamId]);
    }

    /**
     * Finish a tracked request stream.
     */
    public function finishRequestStream(int $streamId): void
    {
        $stream = $this->requestStreams[$streamId] ?? null;
        if ($stream === null) {
            throw new Http3Exception(ErrorCode::REQUEST_INCOMPLETE, 'Unknown HTTP/3 request stream was finished.');
        }

        $stream->finish();
    }

    /**
     * Build the local control-stream preamble and SETTINGS frame.
     */
    public function localControlPreamble(): string
    {
        return ControlStream::preamble($this->localSettings);
    }

    /**
     * Build the local QPACK decoder-stream type preamble.
     */
    public function localQpackDecoderPreamble(): string
    {
        return VarIntCodec::encode(StreamType::QPACK_DECODER->value);
    }

    /**
     * Build the local QPACK encoder-stream type preamble.
     */
    public function localQpackEncoderPreamble(): string
    {
        return VarIntCodec::encode(StreamType::QPACK_ENCODER->value);
    }

    /**
     * Return the locally advertised HTTP/3 settings.
     */
    public function localSettings(): Settings
    {
        return $this->localSettings;
    }

    /**
     * Return peer settings once received on the control stream.
     */
    public function peerSettings(): ?Settings
    {
        return $this->peerControl->peerSettings();
    }

    /**
     * Feed bytes from a peer unidirectional stream.
     */
    public function pushPeerUnidirectional(int $streamId, string $bytes): void
    {
        $stream = $this->peerUnidirectionalStreams[$streamId] ?? $this->createPeerUnidirectional($streamId);
        $payload = $stream->push($bytes);
        $type = $stream->type();
        if ($type === null) {
            return;
        }
        if (!$stream->claimed()) {
            $this->claimPeerUnidirectional($stream, $type);
        }

        $this->processPeerUnidirectionalPayload($type, $payload);
    }

    /**
     * Feed bytes into a request stream and return its state object.
     */
    public function pushRequestStream(int $streamId, string $bytes): RequestStream
    {
        $stream = $this->requestStreams[$streamId] ?? $this->createRequestStream($streamId);
        $stream->push($bytes);

        return $stream;
    }

    /**
     * Release a request stream and cancel it first when unfinished.
     */
    public function releaseRequestStream(int $streamId): void
    {
        $stream = $this->requestStreams[$streamId] ?? null;
        if ($stream === null) {
            return;
        }
        if (!$stream->finished()) {
            $stream->cancel();
        }

        unset($this->requestStreams[$streamId]);
    }

    /**
     * Return a tracked request stream by ID.
     */
    public function requestStream(int $streamId): ?RequestStream
    {
        return $this->requestStreams[$streamId] ?? null;
    }

    /**
     * Return the response QPACK encoder once peer settings permit it.
     */
    public function responseEncoder(): ?Encoder
    {
        return $this->encoder;
    }

    /**
     * Take pending instructions for the local QPACK decoder stream.
     */
    public function takeLocalQpackDecoderInstructions(): string
    {
        return $this->decoder->takeDecoderInstructions();
    }

    /**
     * Take pending instructions for the local QPACK encoder stream.
     */
    public function takeLocalQpackEncoderInstructions(): string
    {
        return $this->encoder?->takeEncoderInstructions() ?? '';
    }

    private function claimPeerUnidirectional(PeerUnidirectionalStream $stream, int $typeValue): void
    {
        $type = StreamType::tryFrom($typeValue);
        if ($type === StreamType::PUSH) {
            throw new Http3Exception(
                ErrorCode::STREAM_CREATION_ERROR,
                'Clients cannot create HTTP/3 push streams.',
            );
        }
        if ($type === null) {
            $stream->claim();

            return;
        }

        $existing = $this->criticalPeerStreams[$type->value] ?? null;
        if ($existing !== null) {
            throw new Http3Exception(
                ErrorCode::STREAM_CREATION_ERROR,
                sprintf('Duplicate HTTP/3 critical stream type 0x%x.', $type->value),
            );
        }

        $this->criticalPeerStreams[$type->value] = $stream->streamId();
        $stream->claim();
    }

    private function configureEncoder(): void
    {
        if ($this->encoder !== null) {
            return;
        }

        $settings = $this->peerControl->peerSettings();
        if ($settings === null) {
            return;
        }

        $tableCapacity = min($settings->qpackMaxTableCapacity(), $this->limits->qpackMaxTableCapacity);
        $blockedStreams = min($settings->qpackBlockedStreams(), $this->limits->qpackMaxBlockedStreams);
        $fieldSectionBytes = min($settings->maxFieldSectionSize(), $this->limits->maxFieldSectionBytes);
        $this->encoder = new Encoder(
            $tableCapacity,
            $blockedStreams,
            $fieldSectionBytes,
            $tableCapacity,
        );

        if ($this->pendingPeerDecoderInstructions !== '') {
            $this->encoder->pushDecoderInstructions($this->pendingPeerDecoderInstructions);
            $this->pendingPeerDecoderInstructions = '';
        }
    }

    private function createPeerUnidirectional(int $streamId): PeerUnidirectionalStream
    {
        ++$this->peerUnidirectionalStreamsCreated;
        if ($this->peerUnidirectionalStreamsCreated > $this->limits->maxPeerUnidirectionalStreamsPerConnection) {
            throw new Http3Exception(
                ErrorCode::EXCESSIVE_LOAD,
                'HTTP/3 peer unidirectional stream churn limit exceeded.',
            );
        }

        return $this->peerUnidirectionalStreams[$streamId] = new PeerUnidirectionalStream($streamId);
    }

    private function createRequestStream(int $streamId): RequestStream
    {
        ++$this->requestStreamsCreated;
        if ($this->requestStreamsCreated > $this->limits->maxRequestStreamsPerConnection) {
            throw new Http3Exception(ErrorCode::EXCESSIVE_LOAD, 'HTTP/3 request stream churn limit exceeded.');
        }
        if (count($this->requestStreams) >= $this->limits->maxConcurrentRequestStreams) {
            throw new Http3Exception(ErrorCode::REQUEST_REJECTED, 'HTTP/3 concurrent request stream limit exceeded.');
        }

        return $this->requestStreams[$streamId] = new RequestStream($streamId, $this->decoder, $this->limits);
    }

    private function processControl(string $payload): void
    {
        if ($payload === '') {
            return;
        }

        $this->peerControl->push($payload);
        $this->configureEncoder();
    }

    private function processPeerDecoderInstructions(string $payload): void
    {
        if ($payload === '') {
            return;
        }
        if ($this->encoder !== null) {
            $this->encoder->pushDecoderInstructions($payload);

            return;
        }
        if (strlen($payload) > $this->limits->maxPendingQpackDecoderBytes - strlen($this->pendingPeerDecoderInstructions)) {
            throw new Http3Exception(
                ErrorCode::EXCESSIVE_LOAD,
                'HTTP/3 peer QPACK decoder instructions exceed the pre-SETTINGS buffer limit.',
            );
        }

        $this->pendingPeerDecoderInstructions .= $payload;
    }

    private function processPeerEncoderInstructions(string $payload): void
    {
        if ($payload === '') {
            return;
        }

        foreach ($this->decoder->pushEncoderInstructions($payload) as $ready) {
            $stream = $this->requestStreams[$ready->streamId] ?? null;
            if ($stream === null) {
                throw new Http3Exception(
                    ErrorCode::INTERNAL_ERROR,
                    'QPACK unblocked a request stream that is no longer tracked.',
                );
            }

            $stream->resume($ready->section);
        }
    }

    private function processPeerUnidirectionalPayload(int $type, string $payload): void
    {
        match (StreamType::tryFrom($type)) {
            StreamType::CONTROL => $this->processControl($payload),
            StreamType::QPACK_DECODER => $this->processPeerDecoderInstructions($payload),
            StreamType::QPACK_ENCODER => $this->processPeerEncoderInstructions($payload),
            default => null,
        };
    }
}
