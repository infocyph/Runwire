<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http;

use InvalidArgumentException;

final readonly class HeaderField
{
    public string $name;
    public string $value;

    public function __construct(string $name, string $value)
    {
        if (!self::validName($name)) {
            throw new InvalidArgumentException('Invalid HTTP header field name.');
        }
        if (!self::validValue($value)) {
            throw new InvalidArgumentException('Invalid HTTP header field value.');
        }

        $this->name = strtolower($name);
        $this->value = $value;
    }

    private static function validName(string $name): bool
    {
        return $name !== '' && preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) === 1;
    }

    private static function validValue(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) !== 1;
    }
}
