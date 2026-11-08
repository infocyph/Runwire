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
