<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Closure;
use Infocyph\Runwire\Http\Http1\Http1Limits;
use Infocyph\Runwire\Http\Http2\Http2Limits;
use Infocyph\Runwire\Http\Http3\Http3Options;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Supervisor\WorkerContext;
use InvalidArgumentException;

final readonly class Server
{
    /** @var Closure(HttpRequest, ResponseWriterInterface): void */
    public Closure $handler;

    /** @var Closure(WorkerContext): callable|null */
    private ?Closure $workerHandlerFactory;

    /**
     * @param callable(HttpRequest, ResponseWriterInterface): void $handler
     * @param callable(WorkerContext): callable|null $workerHandlerFactory
     */
    public function __construct(
        public string $name,
        public string $address,
        callable $handler,
        public int $workers = 1,
        public int $workerConnectionLimit = 10_000,
        public ListenerOptions $listener = new ListenerOptions(),
        public ConnectionLimits $connection = new ConnectionLimits(),
        public ?TlsOptions $tls = null,
        public Http1Limits $http1 = new Http1Limits(),
        public Http2Limits $http2 = new Http2Limits(),
        public ?Http3Options $http3 = null,
        public float $workerReadyTimeoutSeconds = 10.0,
        public float $workerShutdownTimeoutSeconds = 30.0,
        ?callable $workerHandlerFactory = null,
    ) {
        if ($name === '' || strlen($name) > 96 || preg_match('/^[A-Za-z0-9._-]+$/D', $name) !== 1) {
            throw new InvalidArgumentException('Server name must be 1-96 safe identifier characters.');
        }
        if ($address === '') {
            throw new InvalidArgumentException('Server address cannot be empty.');
        }
        if ($workers < 0 || $workers > 1_024) {
            throw new InvalidArgumentException('Server worker count must be between 0 (automatic) and 1024.');
        }
        if ($workerConnectionLimit < 1 || $workerConnectionLimit > 1_000_000) {
            throw new InvalidArgumentException('Worker connection limit must be between 1 and 1000000.');
        }
        if ($http3 !== null && $tls === null) {
            throw new InvalidArgumentException('HTTP/3 requires TLS certificate configuration.');
        }
        foreach ([
            'workerReadyTimeoutSeconds' => $workerReadyTimeoutSeconds,
            'workerShutdownTimeoutSeconds' => $workerShutdownTimeoutSeconds,
        ] as $name => $seconds) {
            if (!is_finite($seconds) || $seconds <= 0) {
                throw new InvalidArgumentException(sprintf('%s must be finite and positive.', $name));
            }
        }

        /** @var Closure(HttpRequest, ResponseWriterInterface): void $handlerClosure */
        $handlerClosure = Closure::fromCallable($handler);
        $this->handler = $handlerClosure;

        if ($workerHandlerFactory === null) {
            $this->workerHandlerFactory = null;
        } else {
            /** @var Closure(WorkerContext): callable $factoryClosure */
            $factoryClosure = Closure::fromCallable($workerHandlerFactory);
            $this->workerHandlerFactory = $factoryClosure;
        }
    }

    /** @param callable(HttpRequest, ResponseWriterInterface): void $handler */
    public static function http(string $address, callable $handler, string $name = 'web'): self
    {
        return new self($name, $address, $handler);
    }

    /** @param callable(WorkerContext): callable $factory */
    public static function httpFactory(string $address, callable $factory, string $name = 'web'): self
    {
        return new self(
            $name,
            $address,
            static function (): void {},
            workerHandlerFactory: $factory,
        );
    }

    /** @return Closure(HttpRequest, ResponseWriterInterface): void */
    public function handlerFor(WorkerContext $context): Closure
    {
        if ($this->workerHandlerFactory === null) {
            return $this->handler;
        }

        $handler = ($this->workerHandlerFactory)($context);
        /** @var Closure(HttpRequest, ResponseWriterInterface): void $closure */
        $closure = Closure::fromCallable($handler);

        return $closure;
    }

    public function withHttp3(?Http3Options $http3 = new Http3Options()): self
    {
        return new self(
            $this->name,
            $this->address,
            $this->handler,
            $this->workers,
            $this->workerConnectionLimit,
            $this->listener,
            $this->connection,
            $this->tls,
            $this->http1,
            $this->http2,
            $http3,
            $this->workerReadyTimeoutSeconds,
            $this->workerShutdownTimeoutSeconds,
            $this->workerHandlerFactory,
        );
    }

    public function withTls(?TlsOptions $tls): self
    {
        return new self(
            $this->name,
            $this->address,
            $this->handler,
            $this->workers,
            $this->workerConnectionLimit,
            $this->listener,
            $this->connection,
            $tls,
            $this->http1,
            $this->http2,
            $this->http3,
            $this->workerReadyTimeoutSeconds,
            $this->workerShutdownTimeoutSeconds,
            $this->workerHandlerFactory,
        );
    }

    public function withWorkerConnectionLimit(int $limit): self
    {
        return new self(
            $this->name,
            $this->address,
            $this->handler,
            $this->workers,
            $limit,
            $this->listener,
            $this->connection,
            $this->tls,
            $this->http1,
            $this->http2,
            $this->http3,
            $this->workerReadyTimeoutSeconds,
            $this->workerShutdownTimeoutSeconds,
            $this->workerHandlerFactory,
        );
    }

    /** @param callable(WorkerContext): callable $factory */
    public function withWorkerHandlerFactory(callable $factory): self
    {
        return new self(
            $this->name,
            $this->address,
            $this->handler,
            $this->workers,
            $this->workerConnectionLimit,
            $this->listener,
            $this->connection,
            $this->tls,
            $this->http1,
            $this->http2,
            $this->http3,
            $this->workerReadyTimeoutSeconds,
            $this->workerShutdownTimeoutSeconds,
            $factory,
        );
    }

    public function withWorkers(int $workers): self
    {
        return new self(
            $this->name,
            $this->address,
            $this->handler,
            $workers,
            $this->workerConnectionLimit,
            $this->listener,
            $this->connection,
            $this->tls,
            $this->http1,
            $this->http2,
            $this->http3,
            $this->workerReadyTimeoutSeconds,
            $this->workerShutdownTimeoutSeconds,
            $this->workerHandlerFactory,
        );
    }
}
