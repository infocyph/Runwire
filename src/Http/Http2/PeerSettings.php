<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http2;

use Infocyph\Runwire\Http\Http2\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http2\Internal\ConnectionError;

final class PeerSettings
{
    public const int ENABLE_PUSH = 0x2;

    public const int HEADER_TABLE_SIZE = 0x1;

    public const int INITIAL_WINDOW_SIZE = 0x4;

    public const int MAX_CONCURRENT_STREAMS = 0x3;

    public const int MAX_FRAME_SIZE = 0x5;

    public const int MAX_HEADER_LIST_SIZE = 0x6;

    public bool $enablePush = true;

    public int $headerTableSize = 4_096;

    public int $initialWindowSize = 65_535;

    public ?int $maxConcurrentStreams = null;

    public int $maxFrameSize = 16_384;

    public ?int $maxHeaderListSize = null;

    /** @return array<int, int> */
    public static function local(Http2Limits $limits): array
    {
        return [
            self::HEADER_TABLE_SIZE => $limits->maxDynamicTableBytes,
            self::MAX_CONCURRENT_STREAMS => $limits->maxConcurrentStreams,
            self::INITIAL_WINDOW_SIZE => $limits->initialReceiveWindow(),
            self::MAX_FRAME_SIZE => $limits->maxInboundFrameSize,
            self::MAX_HEADER_LIST_SIZE => $limits->maxHeaderListBytes,
        ];
    }

    public function apply(string $payload): int
    {
        if (strlen($payload) % 6 !== 0) {
            throw new ConnectionError(ErrorCode::FRAME_SIZE_ERROR, 'HTTP/2 SETTINGS payload length must be a multiple of six.');
        }

        $previousInitial = $this->initialWindowSize;
        for ($offset = 0, $length = strlen($payload); $offset < $length; $offset += 6) {
            /** @var array{1: int}|false $identifierData */
            $identifierData = unpack('n', substr($payload, $offset, 2));
            /** @var array{1: int}|false $valueData */
            $valueData = unpack('N', substr($payload, $offset + 2, 4));
            if ($identifierData === false || $valueData === false) {
                throw new ConnectionError(ErrorCode::FRAME_SIZE_ERROR, 'Unable to decode HTTP/2 SETTINGS payload.');
            }
            $this->applyOne($identifierData[1], $valueData[1]);
        }

        return $this->initialWindowSize - $previousInitial;
    }

    private function applyOne(int $identifier, int $value): void
    {
        match ($identifier) {
            self::HEADER_TABLE_SIZE => $this->headerTableSize = $value,
            self::ENABLE_PUSH => $this->enablePush = $this->pushValue($value),
            self::MAX_CONCURRENT_STREAMS => $this->maxConcurrentStreams = $value,
            self::INITIAL_WINDOW_SIZE => $this->initialWindowSize = $this->windowValue($value),
            self::MAX_FRAME_SIZE => $this->maxFrameSize = $this->frameSizeValue($value),
            self::MAX_HEADER_LIST_SIZE => $this->maxHeaderListSize = $value,
            default => null,
        };
    }

    private function frameSizeValue(int $value): int
    {
        if ($value < 16_384 || $value > 0xFF_FFFF) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'SETTINGS_MAX_FRAME_SIZE is outside the protocol range.');
        }

        return $value;
    }

    private function pushValue(int $value): bool
    {
        if ($value > 1) {
            throw new ConnectionError(ErrorCode::PROTOCOL_ERROR, 'SETTINGS_ENABLE_PUSH must be zero or one.');
        }

        return $value === 1;
    }

    private function windowValue(int $value): int
    {
        if ($value > 0x7FFF_FFFF) {
            throw new ConnectionError(ErrorCode::FLOW_CONTROL_ERROR, 'SETTINGS_INITIAL_WINDOW_SIZE exceeds 2147483647.');
        }

        return $value;
    }
}
