<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;

it('normalizes header names while preserving ordered duplicate fields', function (): void {
    $headers = new Headers([
        new HeaderField('X-Test', 'one'),
        new HeaderField('Set-Cookie', 'a=1'),
        new HeaderField('x-test', 'two'),
    ]);

    expect(array_map(static fn (HeaderField $field): string => $field->name, $headers->fields()))
        ->toBe(['x-test', 'set-cookie', 'x-test'])
        ->and($headers->all('X-Test'))->toBe(['one', 'two'])
        ->and($headers->first('set-cookie'))->toBe('a=1');
});

it('rejects invalid header names and control characters', function (): void {
    expect(fn () => new HeaderField('bad name', 'value'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new HeaderField('x-test', "bad\r\nvalue"))->toThrow(InvalidArgumentException::class);
});
