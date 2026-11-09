<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Tests;

use Infocyph\Runwire\Network\DnsResolverInterface;
use Infocyph\Runwire\RequestDeadline;
use InvalidArgumentException;
use Throwable;

final class CachedDnsFakeResolver implements DnsResolverInterface
{
    public int $requests = 0;

    public int $cancelled = 0;

    /** @var array<int, callable(list<string>, ?Throwable): void> */
    public array $jobs = [];

    /**
     * @throws \InvalidArgumentException
     */
    #[\Override]
    public function resolve(
        string $hostname,
        callable $completion,
        ?\Infocyph\Runwire\CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int {
        if ($hostname === '' || $token?->isCancelled() || $deadline?->expired()) {
            throw new InvalidArgumentException('Test DNS lookup requires a live hostname and caller.');
        }
        ++$this->requests;
        $this->jobs[$this->requests] = $completion;

        return $this->requests;
    }

    #[\Override]
    public function cancel(int $id): bool
    {
        if (!isset($this->jobs[$id])) {
            return false;
        }
        unset($this->jobs[$id]);
        ++$this->cancelled;

        return true;
    }

    /** @param list<string> $addresses */
    public function complete(int $id, array $addresses, ?Throwable $error = null): void
    {
        $callback = $this->jobs[$id];
        unset($this->jobs[$id]);
        $callback($addresses, $error);
    }
}
