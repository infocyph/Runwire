<?php

declare(strict_types=1);

use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;

it('exposes the canonical runtime driver values', function (): void {
    $values = array_column(RuntimeDriver::cases(), 'value');
    sort($values);

    expect($values)->toBe([
        'auto',
        'fpm',
        'frankenphp',
        'native',
        'roadrunner',
        'swoole',
    ]);
});
