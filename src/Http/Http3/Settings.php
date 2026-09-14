<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

use Infocyph\Runwire\Http\Http3\Enum\SettingIdentifier;

/**
 * Stores validated HTTP/3 SETTINGS values and protocol defaults.
 */
final readonly class Settings
{
    /** @var array<int, int> */
    private array $values;

    /** @param array<int, int> $values */
    public function __construct(array $values = [])
    {
        $normalized = [];

        foreach ($values as $identifier => $value) {
            if ($identifier < 0 || $identifier > VarIntCodec::MAX_VALUE) {
                throw new \InvalidArgumentException('HTTP/3 setting identifier must fit a QUIC variable-length integer.');
            }
            if ($value < 0 || $value > VarIntCodec::MAX_VALUE) {
                throw new \InvalidArgumentException('HTTP/3 setting value must fit a QUIC variable-length integer.');
            }
            if (SettingsCodec::reservedIdentifier($identifier)) {
                throw new \InvalidArgumentException(sprintf('Reserved HTTP/3 setting identifier 0x%x cannot be sent.', $identifier));
            }

            $normalized[$identifier] = $value;
        }

        $this->values = $normalized;
    }

    /**
     * Build the server SETTINGS advertised from configured HTTP/3 limits.
     */
    public static function serverDefaults(Http3Limits $limits): self
    {
        return new self([
            SettingIdentifier::QPACK_MAX_TABLE_CAPACITY->value => $limits->qpackMaxTableCapacity,
            SettingIdentifier::MAX_FIELD_SECTION_SIZE->value => $limits->maxFieldSectionBytes,
            SettingIdentifier::QPACK_BLOCKED_STREAMS->value => $limits->qpackMaxBlockedStreams,
        ]);
    }

    /** @return array<int, int> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Return the peer maximum field-section size.
     */
    public function maxFieldSectionSize(): int
    {
        return $this->values[SettingIdentifier::MAX_FIELD_SECTION_SIZE->value] ?? VarIntCodec::MAX_VALUE;
    }

    /**
     * Return the peer QPACK blocked-stream limit.
     */
    public function qpackBlockedStreams(): int
    {
        return $this->values[SettingIdentifier::QPACK_BLOCKED_STREAMS->value] ?? 0;
    }

    /**
     * Return the peer QPACK dynamic-table capacity limit.
     */
    public function qpackMaxTableCapacity(): int
    {
        return $this->values[SettingIdentifier::QPACK_MAX_TABLE_CAPACITY->value] ?? 0;
    }

    /**
     * Return a setting value or the supplied default when absent.
     */
    public function value(int $identifier, ?int $default = null): ?int
    {
        return $this->values[$identifier] ?? $default;
    }
}
