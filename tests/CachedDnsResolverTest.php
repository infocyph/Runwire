<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\CachedDnsResolver;
use Infocyph\Runwire\Network\DnsResolverInterface;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
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
            if ($hostname === '' || $token?->isCancelled() || $deadline?->expired()) {
                throw new InvalidArgumentException('Test DNS lookup requires a live hostname and caller.');
            }
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
    $source->cancel(CancellationReason::HOST_CANCELLED);
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
        expect($ips)->toBe([]);
        $errors[] = $failure;
    });
    $two = $resolver->resolve('EXAMPLE.ORG', function ($ips, $failure) use (&$errors): void {
        expect($ips)->toBe([]);
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
        expect($ips)->toBe([]);
        $errors[] = $failure;
    });
    expect(fn() => $resolver->resolve('other.invalid', static function (): void {}))
        ->toThrow(OverflowException::class);
    $upstream->complete(1, [], new RuntimeException('DNS returned no A or AAAA addresses.'));
    $resolver->resolve('missing.invalid', function ($ips, $failure) use (&$errors): void {
        expect($ips)->toBe([]);
        $errors[] = $failure;
    });
    $loop->run();
    expect($upstream->requests)->toBe(1)
        ->and($errors)->toHaveCount(2)
        ->and($errors[1])->toBeInstanceOf(RuntimeException::class);
    $resolver->resolve('other.invalid', function ($ips, $failure) use (&$errors): void {
        expect($ips)->toBe([]);
        $errors[] = $failure;
    });
    $upstream->complete(2, [], new RuntimeException('transient worker failure'));
    $resolver->resolve('other.invalid', function ($ips, $failure) use (&$errors): void {
        expect($ips)->toBe([]);
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
        expect($ips)->toBe([]);
        $received[] = $failure;
    }, deadline: new RequestDeadline(0));

    expect($upstream->requests)->toBe(0)
        ->and($received)->toHaveCount(1)
        ->and($received[0])->toBeInstanceOf(Throwable::class);
    $resolver->close();
});

it('settles every coalesced DNS caller before surfacing a consumer exception', function (int $throwAt, bool $lookupError): void {
    $loop = new SelectLoop();
    $upstream = cachedDnsFake();
    $resolver = new CachedDnsResolver($loop, $upstream);
    $source = new CancellationSource();
    $seen = [];
    $lookupFailure = $lookupError ? new RuntimeException('upstream failed') : null;
    for ($index = 0; $index < 3; ++$index) {
        $resolver->resolve('example.org', static function ($addresses, $error) use ($index, $throwAt, &$seen, $lookupFailure): void {
            $seen[] = $index;
            expect($error)->toBe($lookupFailure)
                ->and($addresses)->toBe($lookupFailure === null ? ['8.8.8.8'] : []);
            if ($index === $throwAt) {
                throw new RuntimeException('consumer failed');
            }
        }, $source->token(), RequestDeadline::afterSeconds(1, hrtime(true)));
    }
    expect(fn() => $upstream->complete(1, $lookupError ? [] : ['8.8.8.8'], $lookupFailure))
        ->toThrow(RuntimeException::class, 'consumer failed');
    expect($seen)->toBe([0, 1, 2])
        ->and($resolver->diagnostics()['waiters'])->toBe(0)
        ->and($resolver->diagnostics()['pendingHosts'])->toBe(0)
        ->and($source->token()->subscriptionCount())->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
    $resolver->close();
    $source->dispose();
})->with([[0, false], [1, false], [0, true], [1, true]]);

it('closes all DNS callers and upstream groups when a close callback throws', function (): void {
    $loop = new SelectLoop();
    $upstream = cachedDnsFake();
    $resolver = new CachedDnsResolver($loop, $upstream);
    $source = new CancellationSource();
    $seen = [];
    foreach (['one.example', 'two.example'] as $index => $host) {
        $resolver->resolve($host, static function ($addresses, $error) use ($index, &$seen): void {
            $seen[] = $index;
            expect($addresses)->toBe([])->and($error)->toBeInstanceOf(\Infocyph\Runwire\Exception\CancelledException::class);
            if ($index === 0) {
                throw new RuntimeException('close consumer failed');
            }
        }, $source->token(), RequestDeadline::afterSeconds(1, hrtime(true)));
    }
    expect(fn() => $resolver->close())->toThrow(RuntimeException::class, 'close consumer failed');
    expect($seen)->toBe([0, 1])->and($upstream->cancelled)->toBe(2)
        ->and($resolver->diagnostics())->toBe(['cached' => 0, 'pendingHosts' => 0, 'waiters' => 0])
        ->and($source->token()->subscriptionCount())->toBe(0)
        ->and($loop->diagnostics()->timersActive)->toBe(0);
    $resolver->close();
    $source->dispose();
});

it('preserves reentrant DNS lookups and cancellation while an older fanout fails', function (): void {
    $loop = new SelectLoop();
    $upstream = cachedDnsFake();
    $resolver = new CachedDnsResolver($loop, $upstream, ttl: 0);
    $cancelled = 0;
    $next = [];
    $second = 0;
    $resolver->resolve('example.org', static function () use ($resolver, &$second, &$next): void {
        $resolver->cancel($second);
        $resolver->resolve('example.org', static function ($ips) use (&$next): void { $next = $ips; });
        throw new RuntimeException('first consumer failed');
    });
    $second = $resolver->resolve('example.org', static function ($ips, $error) use (&$cancelled): void {
        expect($ips)->toBe([])->and($error)->toBeInstanceOf(\Infocyph\Runwire\Exception\CancelledException::class);
        ++$cancelled;
    });
    expect(fn() => $upstream->complete(1, ['8.8.8.8']))->toThrow(RuntimeException::class, 'first consumer failed');
    expect($cancelled)->toBe(1)->and($upstream->requests)->toBe(2)
        ->and($resolver->diagnostics()['pendingHosts'])->toBe(1);
    $upstream->complete(2, ['1.1.1.1']);
    expect($next)->toBe(['1.1.1.1'])->and($resolver->diagnostics()['waiters'])->toBe(0);
    $resolver->close();
});

it('surfaces synchronous DNS consumer failures without poisoning a new lookup', function (): void {
    $upstream = new class implements DnsResolverInterface {
        public $pending;
        public int $requests = 0;
        public function resolve(string $hostname, callable $completion, ?\Infocyph\Runwire\CancellationToken $token = null, ?RequestDeadline $deadline = null): int
        {
            if ($hostname !== 'example.org' || $token?->isCancelled() || $deadline?->expired()) {
                throw new InvalidArgumentException('Synchronous DNS fixture requires a live example.org lookup.');
            }
            $id = ++$this->requests;
            if ($id === 1) {
                $completion(['8.8.8.8'], null);
            } else {
                $this->pending = $completion;
            }
            return $id;
        }
        public function cancel(int $id): bool { return $id > 0; }
    };
    $resolver = new CachedDnsResolver(new SelectLoop(), $upstream, ttl: 0);
    $next = [];
    expect(static function () use ($resolver, &$next): void {
        $resolver->resolve('example.org', static function () use ($resolver, &$next): void {
            $resolver->resolve('example.org', static function ($ips) use (&$next): void { $next = $ips; });
            throw new RuntimeException('synchronous consumer failed');
        });
    })->toThrow(RuntimeException::class, 'synchronous consumer failed');
    expect($resolver->diagnostics()['waiters'])->toBe(1);
    ($upstream->pending)(['1.1.1.1'], null);
    expect($next)->toBe(['1.1.1.1'])->and($resolver->diagnostics()['waiters'])->toBe(0);
    $resolver->close();
});
