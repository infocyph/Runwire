<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Http3Exception;

/**
 * Decodes QPACK field sections and manages blocked-stream acknowledgements.
 */
final class Decoder
{
    private readonly DecoderStreamEncoder $decoderStream;

    private readonly EncoderStreamDecoder $encoderStream;

    private readonly FieldSectionDecoder $fieldDecoder;

    private readonly DynamicTable $table;

    /** @var array<int, list<string>> */
    private array $blocked = [];

    private int $blockedBytes = 0;

    private string $pendingDecoderInstructions = '';

    /**
     * Create a bounded QPACK decoder.
     */
    public function __construct(
        int $maxTableCapacity,
        private readonly int $maxBlockedStreams,
        int $maxFieldSectionBytes = 65_536,
        int $maxHeaderFields = 128,
        private readonly int $maxBlockedBytes = 1_048_576,
    ) {
        if ($maxBlockedStreams < 0 || $maxBlockedBytes < 0) {
            throw new \InvalidArgumentException('QPACK decoder blocked-stream limits cannot be negative.');
        }

        $this->table = new DynamicTable($maxTableCapacity);
        $this->encoderStream = new EncoderStreamDecoder($this->table, $maxFieldSectionBytes);
        $this->decoderStream = new DecoderStreamEncoder();
        $this->fieldDecoder = new FieldSectionDecoder($this->table, $maxFieldSectionBytes, $maxHeaderFields);
    }

    /**
     * Cancel blocked QPACK work for a request stream and queue cancellation feedback.
     */
    public function cancelStream(int $streamId): void
    {
        foreach ($this->blocked[$streamId] ?? [] as $block) {
            $this->blockedBytes -= strlen($block);
        }

        unset($this->blocked[$streamId]);
        $this->pendingDecoderInstructions .= $this->decoderStream->streamCancellation($streamId);
    }

    /**
     * Decode a field section or retain it when required dynamic entries are unavailable.
     */
    public function decode(string $block, int $streamId): ?DecodedFieldSection
    {
        try {
            $section = $this->fieldDecoder->decode($block);
        } catch (BlockedFieldSectionException) {
            $this->block($block, $streamId);

            return null;
        }

        $this->acknowledge($section, $streamId);

        return $section;
    }

    /** @return list<ReadyFieldSection> */
    public function pushEncoderInstructions(string $bytes): array
    {
        $insertions = $this->encoderStream->push($bytes);
        if ($insertions > 0) {
            $this->pendingDecoderInstructions .= $this->decoderStream->insertCountIncrement($insertions);
        }

        return $this->retryBlocked();
    }

    /**
     * Take and clear pending QPACK decoder-stream instructions.
     */
    public function takeDecoderInstructions(): string
    {
        $instructions = $this->pendingDecoderInstructions;
        $this->pendingDecoderInstructions = '';

        return $instructions;
    }

    private function acknowledge(DecodedFieldSection $section, int $streamId): void
    {
        if ($section->requiredInsertCount > 0) {
            $this->pendingDecoderInstructions .= $this->decoderStream->sectionAcknowledgement($streamId);
        }
    }

    private function block(string $block, int $streamId): void
    {
        $newStream = !isset($this->blocked[$streamId]);
        if ($newStream && count($this->blocked) >= $this->maxBlockedStreams) {
            throw new Http3Exception(
                ErrorCode::QPACK_DECOMPRESSION_FAILED,
                'Peer exceeded advertised QPACK blocked-stream limit.',
            );
        }
        if (strlen($block) > $this->maxBlockedBytes - $this->blockedBytes) {
            throw new Http3Exception(
                ErrorCode::EXCESSIVE_LOAD,
                'Blocked QPACK field sections exceed configured memory limit.',
            );
        }

        $this->blocked[$streamId][] = $block;
        $this->blockedBytes += strlen($block);
    }

    /** @return list<ReadyFieldSection> */
    private function retryBlocked(): array
    {
        $ready = [];

        foreach (array_keys($this->blocked) as $streamId) {
            while (($block = $this->blocked[$streamId][0] ?? null) !== null) {
                try {
                    $section = $this->fieldDecoder->decode($block);
                } catch (BlockedFieldSectionException) {
                    break;
                }

                array_shift($this->blocked[$streamId]);
                $this->blockedBytes -= strlen($block);
                $this->acknowledge($section, $streamId);
                $ready[] = new ReadyFieldSection($streamId, $section);
            }

            if (($this->blocked[$streamId] ?? []) === []) {
                unset($this->blocked[$streamId]);
            }
        }

        return $ready;
    }
}
