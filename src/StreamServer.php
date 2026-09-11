<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Closure;
use Infocyph\Runwire\Network\ConnectionLimits;
use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Network\UnixListenerOptions;
use Infocyph\Runwire\Protocol\FrameCodecInterface;
use Infocyph\Runwire\Protocol\FramedConnection;
use Infocyph\Runwire\Supervisor\WorkerContext;
use InvalidArgumentException;
use LogicException;

final readonly class StreamServer
{
    /** @var Closure(string, FramedConnection): void */
    private Closure $handler;

    /** @var Closure(): FrameCodecInterface */
    private Closure $codecFactory;

    /** @var Closure(WorkerContext): callable|null */
    private ?Closure $workerHandlerFactory;

    /**
     * @param callable(): FrameCodecInterface $codecFactory
     * @param callable(string, FramedConnection): void $handler
     * @param callable(WorkerContext): callable|null $workerHandlerFactory
     */
    public function __construct(
        public string $name,
        public StreamTransport $transport,
        public string $address,
        callable $codecFactory,
        callable $handler,
        public int $workers = 1,
        public int $workerConnectionLimit = 10_000,
        public ListenerOptions $listener = new ListenerOptions(),
        public ConnectionLimits $connection = new ConnectionLimits(),
        public ?TlsOptions $tls = null,
        public ?UnixListenerOptions $unix = null,
        public int $maxFramesPerTick = 256,
        public float $workerReadyTimeoutSeconds = 10.0,
        public float $workerShutdownTimeoutSeconds = 30.0,
        ?callable $workerHandlerFactory = null,
    ) {
        self::validateCore($name, $address, $workers, $workerConnectionLimit, $maxFramesPerTick);
        self::validateTimeouts($workerReadyTimeoutSeconds, $workerShutdownTimeoutSeconds);
        self::validateTransport($transport, $tls, $unix);

        /** @var Closure(): FrameCodecInterface $codecClosure */
        $codecClosure = Closure::fromCallable($codecFactory);
        $this->codecFactory = $codecClosure;

        /** @var Closure(string, FramedConnection): void $handlerClosure */
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

    /**
     * @param callable(): FrameCodecInterface $codecFactory
     * @param callable(string, FramedConnection): void $handler
     */
    public static function tcp(string $address, callable $codecFactory, callable $handler, string $name = 'stream'): self
    {
        return new self($name, StreamTransport::TCP, $address, $codecFactory, $handler);
    }

    /**
     * @param callable(): FrameCodecInterface $codecFactory
     * @param callable(string, FramedConnection): void $handler
     */
    public static function unix(string $path, callable $codecFactory, callable $handler, string $name = 'stream'): self
    {
        return new self($name, StreamTransport::UNIX, $path, $codecFactory, $handler, unix: new UnixListenerOptions());
    }

    public function withWorkers(int $workers): self
    {
        return new self(
            $this->name,
            $this->transport,
            $this->address,
            $this->codecFactory,
            $this->handler,
            $workers,
            $this->workerConnectionLimit,
            $this->listener,
            $this->connection,
            $this->tls,
            $this->unix,
            $this->maxFramesPerTick,
            $this->workerReadyTimeoutSeconds,
            $this->workerShutdownTimeoutSeconds,
            $this->workerHandlerFactory,
        );
    }

    public function withWorkerConnectionLimit(int $limit): self
    {
        return new self(
            $this->name,
            $this->transport,
            $this->address,
            $this->codecFactory,
            $this->handler,
            $this->workers,
            $limit,
            $this->listener,
            $this->connection,
            $this->tls,
            $this->unix,
            $this->maxFramesPerTick,
            $this->workerReadyTimeoutSeconds,
            $this->workerShutdownTimeoutSeconds,
            $this->workerHandlerFactory,
        );
    }

    public function withTls(?TlsOptions $tls): self
    {
        return new self(
            $this->name,
            $this->transport,
            $this->address,
            $this->codecFactory,
            $this->handler,
            $this->workers,
            $this->workerConnectionLimit,
            $this->listener,
            $this->connection,
            $tls,
            $this->unix,
            $this->maxFramesPerTick,
            $this->workerReadyTimeoutSeconds,
            $this->workerShutdownTimeoutSeconds,
            $this->workerHandlerFactory,
        );
    }

    public function withUnixOptions(UnixListenerOptions $options): self
    {
        return new self(
            $this->name,
            $this->transport,
            $this->address,
            $this->codecFactory,
            $this->handler,
            $this->workers,
            $this->workerConnectionLimit,
            $this->listener,
            $this->connection,
            $this->tls,
            $options,
            $this->maxFramesPerTick,
            $this->workerReadyTimeoutSeconds,
            $this->workerShutdownTimeoutSeconds,
            $this->workerHandlerFactory,
        );
    }

    public function withMaxFramesPerTick(int $maxFramesPerTick): self
    {
        return new self(
            $this->name,
            $this->transport,
            $this->address,
            $this->codecFactory,
            $this->handler,
            $this->workers,
            $this->workerConnectionLimit,
            $this->listener,
            $this->connection,
            $this->tls,
            $this->unix,
            $maxFramesPerTick,
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
            $this->transport,
            $this->address,
            $this->codecFactory,
            $this->handler,
            $this->workers,
            $this->workerConnectionLimit,
            $this->listener,
            $this->connection,
            $this->tls,
            $this->unix,
            $this->maxFramesPerTick,
            $this->workerReadyTimeoutSeconds,
            $this->workerShutdownTimeoutSeconds,
            $factory,
        );
    }

    public function codec(): FrameCodecInterface
    {
        return ($this->codecFactory)();
    }

    /** @return Closure(string, FramedConnection): void */
    public function handlerFor(WorkerContext $context): Closure
    {
        if ($this->workerHandlerFactory === null) {
            return $this->handler;
        }
        $handler = ($this->workerHandlerFactory)($context);
        /** @var Closure(string, FramedConnection): void $closure */
        $closure = Closure::fromCallable($handler);
        return $closure;
    }

    private static function validateCore(
        string $name,
        string $address,
        int $workers,
        int $workerConnectionLimit,
        int $maxFramesPerTick,
    ): void {
        self::validateName($name);
        if ($address === '') {
            throw new InvalidArgumentException('Stream server address cannot be empty.');
        }
        if ($workers < 1 || $workers > 1_024) {
            throw new InvalidArgumentException('Stream server worker count must be between 1 and 1024.');
        }
        if ($workerConnectionLimit < 1 || $workerConnectionLimit > 1_000_000) {
            throw new InvalidArgumentException('Worker connection limit must be between 1 and 1000000.');
        }
        if ($maxFramesPerTick <= 0 || $maxFramesPerTick > 65_536) {
            throw new InvalidArgumentException('Maximum frames per tick must be between 1 and 65536.');
        }
    }

    private static function validateTimeouts(float $ready, float $shutdown): void
    {
        foreach ([$ready, $shutdown] as $seconds) {
            if (!is_finite($seconds) || $seconds <= 0) {
                throw new InvalidArgumentException('Worker timeouts must be finite and positive.');
            }
        }
    }

    private static function validateTransport(
        StreamTransport $transport,
        ?TlsOptions $tls,
        ?UnixListenerOptions $unix,
    ): void {
        if ($transport === StreamTransport::UNIX && $tls !== null) {
            throw new LogicException('TLS is only supported for TCP stream servers.');
        }
        if ($transport === StreamTransport::TCP && $unix !== null) {
            throw new LogicException('Unix listener options are only valid for Unix stream servers.');
        }
    }

    private static function validateName(string $name): void
    {
        if ($name === '' || strlen($name) > 96 || preg_match('/^[A-Za-z0-9._-]+$/D', $name) !== 1) {
            throw new InvalidArgumentException('Server name must be 1-96 safe identifier characters.');
        }
    }
}
