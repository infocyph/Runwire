<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests\Support;

use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\RequestResetterInterface;

/**
 * Resets the persistent-state fixture after each request.
 */
final readonly class PersistentFixtureResetter implements RequestResetterInterface
{
    public function __construct(private PersistentFixtureState $state) {}

    public function reset(RequestContext $context): void
    {
        expect($context->attribute('fixture'))->not->toBeNull();
        $this->state->reset();
    }
}
