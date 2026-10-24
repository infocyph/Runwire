<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http;

/**
 * Stores an ordered collection of validated HTTP header fields.
 */
final readonly class Headers
{
    /** @param list<HeaderField> $fields */
    public function __construct(private array $fields = []) {}

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function fromArray(array $headers): self
    {
        $fields = [];
        foreach ($headers as $name => $values) {
            foreach (is_array($values) ? $values : [$values] as $value) {
                $fields[] = new HeaderField($name, $value);
            }
        }

        return new self($fields);
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

    /** @return list<HeaderField> */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Return the first value for a header name.
     */
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

    /**
     * Determine whether a header name is present.
     */
    public function has(string $name): bool
    {
        return $this->first($name) !== null;
    }
}
