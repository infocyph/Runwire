<?php

declare(strict_types=1);

use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\RequestResetterInterface;
use Infocyph\Runwire\Runtime\RequestResetterRegistry;

it('rejects resetter registrations beyond the bounded capacity', function (): void {
    $registry = new RequestResetterRegistry();
    $resetter = new class implements RequestResetterInterface {
        public function reset(RequestContext $context): void {}
    };

    for ($index = 0; $index < 64; ++$index) {
        expect($registry->register($resetter))->toBe($registry);
    }
    expect(fn() => $registry->register($resetter))
        ->toThrow(InvalidArgumentException::class);
});
