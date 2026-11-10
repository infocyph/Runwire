<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\HeaderField;

it('accepts normalized response header fields without permitting response splitting', function (): void {
    $field = new HeaderField('X-Trace', "accepted\tvalue");

    expect($field->name)->toBe('x-trace')
        ->and($field->value)->toBe("accepted\tvalue");

    expect(fn() => new HeaderField("X-Trace\r\nInjected", 'value'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn() => new HeaderField('X-Trace', "value\r\nInjected: true"))
        ->toThrow(InvalidArgumentException::class);
    expect(fn() => new HeaderField('X-Trace', "value\0suffix"))
        ->toThrow(InvalidArgumentException::class);
});

it('preserves per-collection header isolation and name lookup', function (): void {
    $public = \Infocyph\Runwire\Http\Headers::fromArray(['X-Trace' => 'public']);
    $private = \Infocyph\Runwire\Http\Headers::fromArray(['X-Trace' => 'private']);
    expect($public->first('X-TRACE'))->toBe('public')
        ->and($private->first('x-trace'))->toBe('private')
        ->and($public->all('X-Trace'))->toBe(['public'])
        ->and($private->fields()[0]->name)->toBe('x-trace');
});
