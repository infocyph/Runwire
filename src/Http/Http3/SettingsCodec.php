<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Http3;

final class SettingsCodec
{
    /** @var array<int, true> */
    private const array RESERVED_IDENTIFIERS = [
        0x00 => true,
        0x02 => true,
        0x03 => true,
        0x04 => true,
        0x05 => true,
    ];

    public static function decode(string $payload): Settings
    {
        $offset = 0;
        $values = [];

        while ($offset < strlen($payload)) {
            $identifier = VarIntCodec::decode($payload, $offset);
            $value = VarIntCodec::decode($payload, $offset);

            if (isset($values[$identifier])) {
                throw new Http3Exception(
                    ErrorCode::SETTINGS_ERROR,
                    sprintf('Duplicate HTTP/3 setting identifier 0x%x.', $identifier),
                );
            }
            if (self::reservedIdentifier($identifier)) {
                throw new Http3Exception(
                    ErrorCode::SETTINGS_ERROR,
                    sprintf('Reserved HTTP/3 setting identifier 0x%x was received.', $identifier),
                );
            }

            $values[$identifier] = $value;
        }

        return new Settings($values);
    }

    public static function encode(Settings $settings): string
    {
        $payload = '';

        foreach ($settings->all() as $identifier => $value) {
            $payload .= VarIntCodec::encode($identifier) . VarIntCodec::encode($value);
        }

        return $payload;
    }

    public static function reservedIdentifier(int $identifier): bool
    {
        return isset(self::RESERVED_IDENTIFIERS[$identifier]);
    }
}
