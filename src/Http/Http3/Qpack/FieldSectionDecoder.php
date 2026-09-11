<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3\Qpack;

use Infocyph\Runwire\Http\Http3\ErrorCode;
use Infocyph\Runwire\Http\Http3\Http3Exception;

final readonly class FieldSectionDecoder
{
    public function __construct(
        private DynamicTable $table,
        private int $maxFieldSectionBytes = 65_536,
        private int $maxHeaderFields = 128,
    ) {
        if ($maxFieldSectionBytes < 0 || $maxHeaderFields <= 0) {
            throw new \InvalidArgumentException('QPACK field-section limits are invalid.');
        }
    }

    public function decode(string $block): DecodedFieldSection
    {
        if (strlen($block) > $this->maxFieldSectionBytes) {
            throw new Http3Exception(
                ErrorCode::QPACK_DECOMPRESSION_FAILED,
                'QPACK field section exceeds configured compressed limit.',
            );
        }

        $offset = 0;
        $encodedInsertCount = IntegerCodec::decode(
            $block,
            $offset,
            8,
            ErrorCode::QPACK_DECOMPRESSION_FAILED,
        );
        $requiredInsertCount = $this->requiredInsertCount($encodedInsertCount);
        $base = $this->decodeBase($block, $offset, $requiredInsertCount);
        if ($requiredInsertCount > $this->table->insertCount()) {
            throw new BlockedFieldSectionException($requiredInsertCount);
        }

        $fields = [];
        $decodedBytes = 0;
        $dynamicReferenced = false;
        while ($offset < strlen($block)) {
            [$name, $value, $dynamic] = $this->decodeField($block, $offset, $base);
            $decodedBytes += 32 + strlen($name) + strlen($value);
            if ($decodedBytes > $this->maxFieldSectionBytes) {
                throw new Http3Exception(
                    ErrorCode::QPACK_DECOMPRESSION_FAILED,
                    'QPACK decoded field section exceeds configured limit.',
                );
            }

            $fields[] = [$name, $value];
            if (count($fields) > $this->maxHeaderFields) {
                throw new Http3Exception(
                    ErrorCode::QPACK_DECOMPRESSION_FAILED,
                    'QPACK field count exceeds configured limit.',
                );
            }

            $dynamicReferenced = $dynamicReferenced || $dynamic;
        }

        return new DecodedFieldSection($fields, $requiredInsertCount, $base, $dynamicReferenced);
    }

    private function decodeBase(string $block, int &$offset, int $requiredInsertCount): int
    {
        if (!isset($block[$offset])) {
            throw new Http3Exception(
                ErrorCode::QPACK_DECOMPRESSION_FAILED,
                'QPACK field section is missing Base.',
            );
        }

        $negative = (ord($block[$offset]) & 0x80) !== 0;
        $delta = IntegerCodec::decode($block, $offset, 7, ErrorCode::QPACK_DECOMPRESSION_FAILED);
        if (!$negative) {
            return $requiredInsertCount + $delta;
        }
        if ($requiredInsertCount <= $delta) {
            throw new Http3Exception(ErrorCode::QPACK_DECOMPRESSION_FAILED, 'QPACK Base would be negative.');
        }

        return $requiredInsertCount - $delta - 1;
    }

    /** @return array{0: string, 1: string, 2: bool} */
    private function decodeField(string $block, int &$offset, int $base): array
    {
        $first = ord($block[$offset]);
        if (($first & 0x80) !== 0) {
            return $this->indexed($block, $offset, $base);
        }
        if (($first & 0xF0) === 0x10) {
            return $this->indexedPostBase($block, $offset, $base);
        }
        if (($first & 0xC0) === 0x40) {
            return $this->literalNameReference($block, $offset, $base);
        }
        if (($first & 0xE0) === 0x20) {
            return $this->literalName($block, $offset);
        }

        return $this->literalPostBaseNameReference($block, $offset, $base);
    }

    /** @return array{0: string, 1: string, 2: bool} */
    private function indexed(string $block, int &$offset, int $base): array
    {
        $static = (ord($block[$offset]) & 0x40) !== 0;
        $index = IntegerCodec::decode($block, $offset, 6, ErrorCode::QPACK_DECOMPRESSION_FAILED);
        if ($static) {
            [$name, $value] = StaticTable::entry($index);

            return [$name, $value, false];
        }

        $entry = $this->table->entry($this->table->preBaseIndex($base, $index));

        return [$entry['name'], $entry['value'], true];
    }

    /** @return array{0: string, 1: string, 2: bool} */
    private function indexedPostBase(string $block, int &$offset, int $base): array
    {
        $index = IntegerCodec::decode($block, $offset, 4, ErrorCode::QPACK_DECOMPRESSION_FAILED);
        $entry = $this->table->entry($this->table->postBaseIndex($base, $index));

        return [$entry['name'], $entry['value'], true];
    }

    /** @return array{0: string, 1: string, 2: bool} */
    private function literalName(string $block, int &$offset): array
    {
        $name = StringCodec::decode(
            $block,
            $offset,
            3,
            0x08,
            $this->maxFieldSectionBytes,
            ErrorCode::QPACK_DECOMPRESSION_FAILED,
        );
        $value = StringCodec::decode(
            $block,
            $offset,
            7,
            0x80,
            $this->maxFieldSectionBytes,
            ErrorCode::QPACK_DECOMPRESSION_FAILED,
        );

        return [$name, $value, false];
    }

    /** @return array{0: string, 1: string, 2: bool} */
    private function literalNameReference(string $block, int &$offset, int $base): array
    {
        $static = (ord($block[$offset]) & 0x10) !== 0;
        $index = IntegerCodec::decode($block, $offset, 4, ErrorCode::QPACK_DECOMPRESSION_FAILED);
        $name = $static
            ? StaticTable::entry($index)[0]
            : $this->table->entry($this->table->preBaseIndex($base, $index))['name'];
        $value = StringCodec::decode(
            $block,
            $offset,
            7,
            0x80,
            $this->maxFieldSectionBytes,
            ErrorCode::QPACK_DECOMPRESSION_FAILED,
        );

        return [$name, $value, !$static];
    }

    /** @return array{0: string, 1: string, 2: bool} */
    private function literalPostBaseNameReference(string $block, int &$offset, int $base): array
    {
        $index = IntegerCodec::decode($block, $offset, 3, ErrorCode::QPACK_DECOMPRESSION_FAILED);
        $entry = $this->table->entry($this->table->postBaseIndex($base, $index));
        $value = StringCodec::decode(
            $block,
            $offset,
            7,
            0x80,
            $this->maxFieldSectionBytes,
            ErrorCode::QPACK_DECOMPRESSION_FAILED,
        );

        return [$entry['name'], $value, true];
    }

    private function requiredInsertCount(int $encodedInsertCount): int
    {
        if ($encodedInsertCount === 0) {
            return 0;
        }

        $maxEntries = $this->table->maxEntries();
        if ($maxEntries === 0) {
            throw new Http3Exception(
                ErrorCode::QPACK_DECOMPRESSION_FAILED,
                'Non-zero QPACK Required Insert Count with zero table capacity.',
            );
        }

        $fullRange = 2 * $maxEntries;
        if ($encodedInsertCount > $fullRange) {
            throw new Http3Exception(
                ErrorCode::QPACK_DECOMPRESSION_FAILED,
                'Invalid encoded QPACK Required Insert Count.',
            );
        }

        $maxValue = $this->table->insertCount() + $maxEntries;
        $maxWrapped = intdiv($maxValue, $fullRange) * $fullRange;
        $required = $maxWrapped + $encodedInsertCount - 1;
        if ($required > $maxValue) {
            if ($required <= $fullRange) {
                throw new Http3Exception(
                    ErrorCode::QPACK_DECOMPRESSION_FAILED,
                    'Invalid wrapped QPACK Required Insert Count.',
                );
            }
            $required -= $fullRange;
        }
        if ($required === 0) {
            throw new Http3Exception(
                ErrorCode::QPACK_DECOMPRESSION_FAILED,
                'QPACK Required Insert Count zero must use zero wire encoding.',
            );
        }

        return $required;
    }
}
