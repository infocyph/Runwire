<?php

declare(strict_types=1);

use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RuntimeEnvironmentProbe;

it('detects the mandatory unix process baseline in the cli test runtime', function (): void {
    $environment = (new RuntimeEnvironmentProbe())->probe();

    expect($environment->sapi)->toBe('cli')
        ->and($environment->supportsFork)->toBeTrue()
        ->and($environment->supportsSignals)->toBeTrue()
        ->and($environment->supportsPosix)->toBeTrue()
        ->and($environment->isAvailable(RuntimeDriver::NATIVE))->toBeTrue();
});
