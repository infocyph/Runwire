<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

final class ControlStream
{
    private readonly FrameParser $parser;

    private ?Settings $peerSettings = null;

    public function __construct(int $maxFramePayloadBytes = 1_048_576)
    {
        $this->parser = new FrameParser($maxFramePayloadBytes);
    }

    public static function preamble(Settings $settings): string
    {
        return VarIntCodec::encode(StreamType::CONTROL->value)
            . FrameWriter::encode(new Frame(
                FrameType::SETTINGS->value,
                SettingsCodec::encode($settings),
            ));
    }

    public function close(): never
    {
        throw new Http3Exception(
            ErrorCode::CLOSED_CRITICAL_STREAM,
            'HTTP/3 control stream was closed.',
        );
    }

    public function peerSettings(): ?Settings
    {
        return $this->peerSettings;
    }

    /** @return list<Frame> */
    public function push(string $bytes): array
    {
        $frames = $this->parser->push($bytes);

        foreach ($frames as $frame) {
            $this->validate($frame);
        }

        return $frames;
    }

    private function validate(Frame $frame): void
    {
        $type = $frame->knownType();

        if ($this->peerSettings === null) {
            if ($type !== FrameType::SETTINGS) {
                throw new Http3Exception(
                    ErrorCode::MISSING_SETTINGS,
                    'HTTP/3 SETTINGS must be the first frame on the control stream.',
                );
            }

            $this->peerSettings = SettingsCodec::decode($frame->payload);

            return;
        }

        if ($type === FrameType::SETTINGS) {
            throw new Http3Exception(
                ErrorCode::FRAME_UNEXPECTED,
                'HTTP/3 SETTINGS can appear only once on the control stream.',
            );
        }

        if (in_array($type, [FrameType::DATA, FrameType::HEADERS, FrameType::PUSH_PROMISE], true)) {
            throw new Http3Exception(
                ErrorCode::FRAME_UNEXPECTED,
                sprintf('HTTP/3 frame type 0x%x is forbidden on the control stream.', $frame->type),
            );
        }
    }
}
