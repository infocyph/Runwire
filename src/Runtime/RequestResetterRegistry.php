<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\RequestContext;
use InvalidArgumentException;
use Throwable;

final class RequestResetterRegistry
{
    private const int MAX_RESETTERS = 64;

    /** @var list<RequestResetterInterface> */
    private array $resetters = [];

    /** @param iterable<RequestResetterInterface> $resetters */
    public function __construct(iterable $resetters = [])
    {
        foreach ($resetters as $resetter) {
            $this->register($resetter);
        }
    }

    public function register(RequestResetterInterface $resetter): self
    {
        if (count($this->resetters) >= self::MAX_RESETTERS) {
            throw new InvalidArgumentException('Request resetter registry limit exceeded.');
        }

        $this->resetters[] = $resetter;

        return $this;
    }

    /** @return list<Throwable> */
    public function reset(RequestContext $context): array
    {
        $failures = [];
        foreach ($this->resetters as $resetter) {
            try {
                $resetter->reset($context);
            } catch (Throwable $error) {
                $failures[] = $error;
            }
        }

        return $failures;
    }
}
