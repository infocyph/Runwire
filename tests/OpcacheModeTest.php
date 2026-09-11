<?php

declare(strict_types=1);

use Infocyph\Runwire\OpcacheMode;

it('keeps opcache policy orthogonal to runtime selection', function (): void {
    expect(array_column(OpcacheMode::cases(), 'value'))->toBe([
        'auto',
        'on',
        'off',
        'required',
    ]);
});
