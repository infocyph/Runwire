<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

use Infocyph\Runwire\Http\Http3\Enum\ErrorCode;
use Infocyph\Runwire\Http\Http3\Http3Exception;
use Infocyph\Runwire\Http\Http3\Qpack\Enum\DecoderInstructionType;

/**
 * Encodes QPACK field sections while tracking peer acknowledgements and table references.
 */
final class Encoder
{
    private readonly DecoderStreamDecoder $decoderStream;

    private readonly EncoderStreamEncoder $encoderStream;

    private readonly DynamicTable $table;

    private int $blockedSections = 0;

    private int $knownReceivedCount = 0;

    /** @var array<int, list<SectionReference>> */
    private array $outstanding = [];

    private string $pendingEncoderInstructions = '';

    /**
     * Create a QPACK encoder constrained by peer settings and local field limits.
     */
    public function __construct(
        int $peerMaxTableCapacity,
        private readonly int $peerMaxBlockedStreams,
        private readonly int $maxFieldSectionBytes = 65_536,
        ?int $dynamicTableCapacity = null,
    ) {
        if ($peerMaxBlockedStreams < 0 || $maxFieldSectionBytes < 0) {
            throw new \InvalidArgumentException('QPACK encoder limits cannot be negative.');
        }

        $this->table = new DynamicTable($peerMaxTableCapacity);
        $this->encoderStream = new EncoderStreamEncoder();
        $this->decoderStream = new DecoderStreamDecoder();
        $capacity = $dynamicTableCapacity ?? $peerMaxTableCapacity;
        if ($capacity < 0 || $capacity > $peerMaxTableCapacity) {
            throw new \InvalidArgumentException('QPACK dynamic table capacity exceeds peer maximum.');
        }
        if ($capacity > 0) {
            $this->table->setCapacityLocal($capacity);
            $this->pendingEncoderInstructions = $this->encoderStream->setCapacity($capacity);
        }
    }

    /** @param list<array{0: string, 1: string}> $fields */
    public function encode(array $fields, int $streamId): EncodedFieldSection
    {
        $this->validateFields($fields);
        $base = $this->table->insertCount();
        $required = 0;
        $references = [];
        $sectionBlocking = false;
        $payload = '';

        try {
            foreach ($fields as [$name, $value]) {
                $payload .= $this->encodeField(
                    strtolower($name),
                    $value,
                    $base,
                    $required,
                    $references,
                    $sectionBlocking,
                );
            }
        } catch (\Throwable $exception) {
            $this->releaseReferences($references);

            throw $exception;
        }

        $prefix = $this->prefix($required, $base);
        if ($references !== []) {
            $this->outstanding[$streamId][] = new SectionReference(
                $required,
                array_values($references),
                $sectionBlocking,
            );
            if ($sectionBlocking) {
                ++$this->blockedSections;
            }
        }

        return new EncodedFieldSection($prefix . $payload, $required, $sectionBlocking);
    }

    /**
     * Return the largest dynamic-table insert count known to be received by the peer.
     */
    public function knownReceivedCount(): int
    {
        return $this->knownReceivedCount;
    }

    /**
     * Apply peer QPACK decoder-stream feedback.
     */
    public function pushDecoderInstructions(string $bytes): void
    {
        foreach ($this->decoderStream->push($bytes) as $instruction) {
            match ($instruction->type) {
                DecoderInstructionType::INSERT_COUNT_INCREMENT => $this->increment($instruction->value),
                DecoderInstructionType::SECTION_ACKNOWLEDGEMENT => $this->acknowledge($instruction->value),
                DecoderInstructionType::STREAM_CANCELLATION => $this->cancel($instruction->value),
            };
        }
    }

    /**
     * Take and clear pending QPACK encoder-stream instructions.
     */
    public function takeEncoderInstructions(): string
    {
        $instructions = $this->pendingEncoderInstructions;
        $this->pendingEncoderInstructions = '';

        return $instructions;
    }

    private static function sensitive(string $name): bool
    {
        return in_array($name, ['authorization', 'cookie', 'set-cookie'], true);
    }

    private function acknowledge(int $streamId): void
    {
        $sections = $this->outstanding[$streamId] ?? [];
        $section = array_shift($sections);
        if (!$section instanceof SectionReference) {
            throw new Http3Exception(
                ErrorCode::QPACK_DECODER_STREAM_ERROR,
                'QPACK section acknowledgment has no outstanding field section.',
            );
        }

        $this->releaseSection($section);
        $this->knownReceivedCount = max($this->knownReceivedCount, $section->requiredInsertCount);
        if ($sections === []) {
            unset($this->outstanding[$streamId]);
        } else {
            $this->outstanding[$streamId] = $sections;
        }
    }

    private function cancel(int $streamId): void
    {
        foreach ($this->outstanding[$streamId] ?? [] as $section) {
            $this->releaseSection($section);
        }

        unset($this->outstanding[$streamId]);
    }

    private function canReference(int $absoluteIndex, bool $sectionBlocking): bool
    {
        $required = CheckedInteger::add(
            $absoluteIndex,
            1,
            ErrorCode::INTERNAL_ERROR,
            'QPACK dynamic reference exceeds the platform integer range.',
        );
        if ($required <= $this->knownReceivedCount) {
            return true;
        }

        return $sectionBlocking || $this->blockedSections < $this->peerMaxBlockedStreams;
    }

    /** @param array<int, int> $references */
    private function encodeDynamicReference(
        int $absoluteIndex,
        int $base,
        int &$required,
        array &$references,
        bool &$sectionBlocking,
    ): string {
        if (!$this->canReference($absoluteIndex, $sectionBlocking)) {
            return '';
        }
        if (!isset($references[$absoluteIndex])) {
            $this->table->pin($absoluteIndex);
            $references[$absoluteIndex] = $absoluteIndex;
        }

        $requiredForIndex = CheckedInteger::add(
            $absoluteIndex,
            1,
            ErrorCode::INTERNAL_ERROR,
            'QPACK dynamic reference exceeds the platform integer range.',
        );
        $required = max($required, $requiredForIndex);
        $sectionBlocking = $sectionBlocking || $requiredForIndex > $this->knownReceivedCount;

        return $absoluteIndex < $base
            ? IntegerCodec::encode($base - $absoluteIndex - 1, 6, 0x80)
            : IntegerCodec::encode($absoluteIndex - $base, 4, 0x10);
    }

    /** @param array<int, int> $references */
    private function encodeField(
        string $name,
        string $value,
        int $base,
        int &$required,
        array &$references,
        bool &$sectionBlocking,
    ): string {
        $static = StaticTable::findExact($name, $value);
        if ($static !== null) {
            return IntegerCodec::encode($static, 6, 0xC0);
        }

        $dynamic = $this->table->findExact($name, $value);
        if ($dynamic !== null) {
            $wire = $this->encodeDynamicReference(
                $dynamic,
                $base,
                $required,
                $references,
                $sectionBlocking,
            );
            if ($wire !== '') {
                return $wire;
            }
        }

        if ($this->shouldIndex($name, $value, $sectionBlocking)) {
            $inserted = $this->insert($name, $value);
            if ($inserted !== null) {
                $wire = $this->encodeDynamicReference(
                    $inserted,
                    $base,
                    $required,
                    $references,
                    $sectionBlocking,
                );
                if ($wire !== '') {
                    return $wire;
                }
            }
        }

        return $this->literal($name, $value);
    }

    private function increment(int $increment): void
    {
        if ($increment <= 0 || $increment > $this->table->insertCount() - $this->knownReceivedCount) {
            throw new Http3Exception(
                ErrorCode::QPACK_DECODER_STREAM_ERROR,
                'Invalid QPACK Insert Count Increment.',
            );
        }

        $this->knownReceivedCount += $increment;
    }

    private function insert(string $name, string $value): ?int
    {
        $staticName = StaticTable::findName($name);
        $index = $this->table->insertLocal($name, $value);
        if ($index === null) {
            return null;
        }

        $this->pendingEncoderInstructions .= $staticName === null
            ? $this->encoderStream->insertLiteralName($name, $value)
            : $this->encoderStream->insertNameReference($staticName, true, $value);

        return $index;
    }

    private function literal(string $name, string $value): string
    {
        $sensitive = self::sensitive($name);
        $staticName = StaticTable::findName($name);
        if ($staticName !== null) {
            return IntegerCodec::encode(
                $staticName,
                4,
                0x40 | ($sensitive ? 0x20 : 0x00) | 0x10,
            ) . StringCodec::encode($value, 7, 0x00, 0x80);
        }

        return StringCodec::encode(
            $name,
            3,
            0x20 | ($sensitive ? 0x10 : 0x00),
            0x08,
        ) . StringCodec::encode($value, 7, 0x00, 0x80);
    }

    private function prefix(int $requiredInsertCount, int $base): string
    {
        if ($requiredInsertCount === 0) {
            return "\x00\x00";
        }

        $maxEntries = $this->table->maxEntries();
        if ($maxEntries === 0) {
            throw new Http3Exception(
                ErrorCode::INTERNAL_ERROR,
                'QPACK dynamic reference exists with zero maximum table capacity.',
            );
        }

        $fullRange = CheckedInteger::multiply(
            $maxEntries,
            2,
            ErrorCode::INTERNAL_ERROR,
            'QPACK Required Insert Count range exceeds the platform integer range.',
        );
        $encodedRequired = ($requiredInsertCount % $fullRange) + 1;
        $prefix = IntegerCodec::encode($encodedRequired, 8);

        return $base >= $requiredInsertCount
            ? $prefix . IntegerCodec::encode($base - $requiredInsertCount, 7)
            : $prefix . IntegerCodec::encode($requiredInsertCount - $base - 1, 7, 0x80);
    }

    /** @param array<int, int> $references */
    private function releaseReferences(array $references): void
    {
        foreach ($references as $absoluteIndex) {
            $this->table->unpin($absoluteIndex);
        }
    }

    private function releaseSection(SectionReference $section): void
    {
        $this->releaseReferences($section->entries);
        if ($section->blocking) {
            $this->blockedSections = max(0, $this->blockedSections - 1);
        }
    }

    private function shouldIndex(string $name, string $value, bool $sectionBlocking): bool
    {
        if ($this->table->capacity() === 0 || self::sensitive($name) || strlen($value) > 1_024) {
            return false;
        }

        return $sectionBlocking || $this->blockedSections < $this->peerMaxBlockedStreams;
    }

    /** @param list<array{0: string, 1: string}> $fields */
    private function validateFields(array $fields): void
    {
        $bytes = 0;

        foreach ($fields as [$name, $value]) {
            $bytes += 32 + strlen($name) + strlen($value);
            if ($bytes > $this->maxFieldSectionBytes) {
                throw new \InvalidArgumentException('QPACK field section exceeds configured limit.');
            }
        }
    }
}
