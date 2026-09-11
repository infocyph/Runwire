<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http;

use InvalidArgumentException;

final readonly class Headers
{
    /** @var list<HeaderField> */
    private array $fields;

    /** @param list<HeaderField> $fields */
    public function __construct(array $fields = [])
    {
        foreach ($fields as $field) {
            if (!$field instanceof HeaderField) {
                throw new InvalidArgumentException('Headers accepts only HeaderField values.');
            }
        }
        $this->fields = array_values($fields);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function fromArray(array $headers): self
    {
        $fields = [];
        foreach ($headers as $name => $values) {
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (!is_string($name) || !is_string($value)) {
                    throw new InvalidArgumentException('Header names and values must be strings.');
                }
                $fields[] = new HeaderField($name, $value);
            }
        }
        return new self($fields);
    }

    /** @return list<HeaderField> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return list<string> */
    public function all(string $name): array
    {
        $name = strtolower($name);
        $values = [];
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                $values[] = $field->value;
            }
        }
        return $values;
    }

    public function first(string $name): ?string
    {
        $name = strtolower($name);
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field->value;
            }
        }
        return null;
    }

    public function has(string $name): bool
    {
        return $this->first($name) !== null;
    }
}
