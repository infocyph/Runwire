<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Internal\AuthorityValidator;
use InvalidArgumentException;

it('rejects authority values with edge whitespace instead of normalizing wire input', function (string $authority): void {
    expect(fn() => AuthorityValidator::normalize($authority, 'https'))
        ->toThrow(InvalidArgumentException::class, 'HTTP authority contains forbidden characters.');
})->with([
    'leading space' => ' example.test',
    'trailing space' => 'example.test ',
    'leading tab' => "\texample.test",
    'trailing tab' => "example.test\t",
]);

it('still canonicalizes valid authority values for comparison', function (): void {
    expect(AuthorityValidator::normalize('Example.Test:443', 'https'))->toBe('example.test')
        ->and(AuthorityValidator::normalize('[2001:0db8::1]:80', 'http'))->toBe('[2001:db8::1]');
});
