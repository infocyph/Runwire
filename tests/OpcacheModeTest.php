<?php

declare(strict_types=1);

use Infocyph\Runwire\Runtime\Enum\OpcacheMode;

it('keeps opcache policy orthogonal to runtime selection', function (): void {
    $values = array_column(OpcacheMode::cases(), 'value');
    sort($values);

    expect($values)->toBe([
        'auto',
        'off',
        'on',
        'required',
    ]);
});
