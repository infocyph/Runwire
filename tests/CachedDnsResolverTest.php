<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\CachedDnsResolver;
use Infocyph\Runwire\Network\DnsResolverInterface;
use Infocyph\Runwire\RequestDeadline;
use Throwable;

function cachedDnsFake(): DnsResolverInterface
{
    return new class implements DnsResolverInterface {
        public int $requests = 0;

        public int $cancelled = 0;

        /** @var array<int,callable> */
        public array $jobs = [];

        public function resolve(
            string $hostname,
            callable $completion,
            ?\Infocyph\Runwire\CancellationToken $token = null,
            ?RequestDeadline $deadline = null,
        ): int {
            ++$this->requests;
            $this->jobs[$this->requests] = $completion;

            return $this->requests;
        }

        public function cancel(int $id): bool
        {
            if (!isset($this->jobs[$id])) {
                return false;
            }
            unset($this->jobs[$id]);
            ++$this->cancelled;

            return true;
        }

        public function complete(int $id, array $addresses, ?Throwable $error = null): void
        {
            $callback = $this->jobs[$id];
            unset($this->jobs[$id]);
            $callback($addresses, $error);
        }
    };
}

it('coalesces pending host lookups but independently cancels each caller', function (): void {
    $loop = new SelectLoop();
    $upstream = cachedDnsFake();
    $resolver = new CachedDnsResolver($loop, $upstream, maxEntries: 2);
    $source = new CancellationSource();
    $results = [];

    $one = $resolver->resolve('Example.COM', function ($ips, $failure) use (&$results): void {
        $results['one'] = [$ips, $failure];
    }, $source->token());
    $two = $resolver->resolve('example.com', function ($ips, $failure) use (&$results): void {
        $results['two'] = [$ips, $failure];
    });
    expect($upstream->requests)->toBe(1)
        ->and($resolver->diagnostics()['pendingHosts'])->toBe(1);
    $source->cancel();
    expect($results['one'][1])->toBeInstanceOf(Throwable::class)
        ->and($resolver->cancel($one))->toBeFalse()
        ->and($upstream->cancelled)->toBe(0);

    $upstream->complete(1, ['8.8.8.8']);
    expect($results['two'][0])->toBe(['8.8.8.8'])
        ->and($results['two'][1])->toBeNull()
        ->and($resolver->cancel($two))->toBeFalse()
        ->and($resolver->diagnostics()['cached'])->toBe(1);
    $resolver->resolve('example.com', function ($ips, $failure) use (&$results): void {
        $results['cache'] = [$ips, $failure];
    });
    $loop->run();
    expect($upstream->requests)->toBe(1)
        ->and($results['cache'][0])->toBe(['8.8.8.8'])
        ->and($resolver->diagnostics()['waiters'])->toBe(0);
    $resolver->close();
    $source->dispose();
});

it('cancels upstream only when last waiter goes away and never leaks watchers', function (): void {
    $loop = new SelectLoop();
    $upstream = cachedDnsFake();
    $resolver = new CachedDnsResolver($loop, $upstream);
    $errors = [];
    $one = $resolver->resolve('example.org', function ($ips, $failure) use (&$errors): void {
        $errors[] = $failure;
    });
    $two = $resolver->resolve('EXAMPLE.ORG', function ($ips, $failure) use (&$errors): void {
        $errors[] = $failure;
    });
    expect($resolver->cancel($one))->toBeTrue()
        ->and($upstream->cancelled)->toBe(0)
        ->and($resolver->cancel($two))->toBeTrue()
        ->and($upstream->cancelled)->toBe(1)
        ->and($resolver->diagnostics()['pendingHosts'])->toBe(0)
        ->and($errors)->toHaveCount(2);
    $resolver->close();
    expect($loop->diagnostics()->timersActive)->toBe(0);
});

it('bounds admissions and negative-caches only explicit DNS misses', function (): void {
    $loop = new SelectLoop();
    $upstream = cachedDnsFake();
    $resolver = new CachedDnsResolver($loop, $upstream, maxEntries: 1, maxPending: 1, maxWaiters: 2);
    $errors = [];
    $resolver->resolve('missing.invalid', function ($ips, $failure) use (&$errors): void {
        $errors[] = $failure;
    });
    expect(fn() => $resolver->resolve('other.invalid', static function (): void {}))
        ->toThrow(OverflowException::class);
    $upstream->complete(1, [], new RuntimeException('DNS returned no A or AAAA addresses.'));
    $resolver->resolve('missing.invalid', function ($ips, $failure) use (&$errors): void {
        $errors[] = $failure;
    });
    $loop->run();
    expect($upstream->requests)->toBe(1)
        ->and($errors)->toHaveCount(2)
        ->and($errors[1])->toBeInstanceOf(RuntimeException::class);
    $resolver->resolve('other.invalid', function ($ips, $failure) use (&$errors): void {
        $errors[] = $failure;
    });
    $upstream->complete(2, [], new RuntimeException('transient worker failure'));
    $resolver->resolve('other.invalid', function ($ips, $failure) use (&$errors): void {
        $errors[] = $failure;
    });
    expect($upstream->requests)->toBe(3);
    $resolver->close();
});

it('rejects expired caller deadlines without submitting upstream DNS', function (): void {
    $loop = new SelectLoop();
    $upstream = cachedDnsFake();
    $resolver = new CachedDnsResolver($loop, $upstream);
    $received = [];
    $resolver->resolve('example.com', function ($ips, $failure) use (&$received): void {
        $received[] = $failure;
    }, deadline: new RequestDeadline(0));

    expect($upstream->requests)->toBe(0)
        ->and($received)->toHaveCount(1)
        ->and($received[0])->toBeInstanceOf(Throwable::class);
    $resolver->close();
});
