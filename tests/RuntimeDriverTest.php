<?php

declare(strict_types=1);

use Infocyph\Runwire\RuntimeDriver;

it('exposes the canonical runtime driver values', function (): void {
    expect(array_column(RuntimeDriver::cases(), 'value'))->toBe([
        'auto',
        'native',
        'fpm',
        'frankenphp',
        'swoole',
        'roadrunner',
    ]);
});
