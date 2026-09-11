<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Closure;
use Infocyph\Runwire\Network\Datagram;
use Infocyph\Runwire\Network\DatagramListener;
use Infocyph\Runwire\Network\DatagramOptions;
use Infocyph\Runwire\Supervisor\WorkerContext;
use InvalidArgumentException;

final readonly class DatagramServer
{
    /** @var Closure(Datagram, DatagramListener): void */
    private Closure $handler;

    /** @var Closure(WorkerContext): callable|null */
    private ?Closure $workerHandlerFactory;

    /** @param callable(Datagram, DatagramListener): void $handler */
    public function __construct(
        public string $name,
        public string $address,
        callable $handler,
        public int $workers = 1,
        public DatagramOptions $options = new DatagramOptions(),
        public float $workerReadyTimeoutSeconds = 10.0,
        public float $workerShutdownTimeoutSeconds = 30.0,
        ?callable $workerHandlerFactory = null,
    ) {
        if ($name === '' || strlen($name) > 96 || preg_match('/^[A-Za-z0-9._-]+$/D', $name) !== 1) {
            throw new InvalidArgumentException('Server name must be 1-96 safe identifier characters.');
        }
        if ($address === '') {
            throw new InvalidArgumentException('Datagram server address cannot be empty.');
        }
        if ($workers < 1 || $workers > 1_024) {
            throw new InvalidArgumentException('Datagram server worker count must be between 1 and 1024.');
        }
        foreach ([$workerReadyTimeoutSeconds, $workerShutdownTimeoutSeconds] as $seconds) {
            if (!is_finite($seconds) || $seconds <= 0) {
                throw new InvalidArgumentException('Worker timeouts must be finite and positive.');
            }
        }
        $this->handler = Closure::fromCallable($handler);
        $this->workerHandlerFactory = $workerHandlerFactory === null ? null : Closure::fromCallable($workerHandlerFactory);
    }

    /** @param callable(Datagram, DatagramListener): void $handler */
    public static function udp(string $address, callable $handler, string $name = 'udp'): self
    {
        return new self($name, $address, $handler);
    }

    public function withWorkers(int $workers): self
    {
        return new self(
            $this->name,
            $this->address,
            $this->handler,
            $workers,
            $this->options,
            $this->workerReadyTimeoutSeconds,
            $this->workerShutdownTimeoutSeconds,
            $this->workerHandlerFactory,
        );
    }

    public function withWorkerHandlerFactory(callable $factory): self
    {
        return new self(
            $this->name,
            $this->address,
            $this->handler,
            $this->workers,
            $this->options,
            $this->workerReadyTimeoutSeconds,
            $this->workerShutdownTimeoutSeconds,
            $factory,
        );
    }

    /** @return Closure(Datagram, DatagramListener): void */
    public function handlerFor(WorkerContext $context): Closure
    {
        if ($this->workerHandlerFactory === null) {
            return $this->handler;
        }
        $handler = ($this->workerHandlerFactory)($context);
        if (!is_callable($handler)) {
            throw new InvalidArgumentException('Worker datagram handler factory must return a callable handler.');
        }
        return Closure::fromCallable($handler);
    }
}
