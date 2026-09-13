<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Closure;
use Infocyph\Runwire\Control\ControlOptions;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Network\DatagramListener;
use Infocyph\Runwire\Network\Enum\StreamTransport;
use Infocyph\Runwire\Network\ListenerOptions;
use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Network\UnixListener;
use Infocyph\Runwire\Network\UnixListenerOptions;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Host\HostDriverFactory;
use Infocyph\Runwire\Runtime\Host\HostDriverInterface;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\Internal\BoundDatagramServer;
use Infocyph\Runwire\Runtime\Internal\BoundServer;
use Infocyph\Runwire\Runtime\Internal\BoundStreamServer;
use Infocyph\Runwire\Runtime\Internal\NativeDatagramWorker;
use Infocyph\Runwire\Runtime\Internal\NativeHttp3Worker;
use Infocyph\Runwire\Runtime\Internal\NativeHttpWorker;
use Infocyph\Runwire\Runtime\Internal\NativeStreamWorker;
use Infocyph\Runwire\Runtime\RuntimeEnvironmentProbe;
use Infocyph\Runwire\Runtime\RuntimeSelection;
use Infocyph\Runwire\Runtime\RuntimeSelector;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\SupervisorEvent;
use Infocyph\Runwire\Supervisor\SupervisorStatus;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;
use LogicException;

final class Runtime
{
    private ?ControlOptions $controlOptions = null;

    private ?RuntimeApplication $hostApplication = null;

    private ?HostDriverInterface $hostDriver = null;

    /** @var list<Closure(SupervisorEvent): void> */
    private array $lifecycleListeners = [];

    private ?RuntimeSelection $selection = null;

    /** @var array<string, Server|StreamServer|DatagramServer> */
    private array $servers = [];

    private bool $started = false;

    private ?Supervisor $supervisor = null;

    private function __construct(
        private readonly RuntimeOptions $options,
        private readonly RuntimeEnvironmentProbe $environmentProbe,
        private readonly RuntimeSelector $selector,
    ) {}

    public static function create(?RuntimeOptions $options = null): self
    {
        return new self(
            $options ?? new RuntimeOptions(),
            new RuntimeEnvironmentProbe(),
            new RuntimeSelector(),
        );
    }

    public function control(ControlOptions $options): self
    {
        if ($this->started) {
            throw new LogicException('Runtime topology is frozen after run() starts.');
        }

        $this->controlOptions = $options;

        return $this;
    }

    public function listen(Server|StreamServer|DatagramServer $server): self
    {
        if ($this->started) {
            throw new LogicException('Runtime topology is frozen after run() starts.');
        }
        if (isset($this->servers[$server->name])) {
            throw new LogicException(sprintf('Server "%s" is already registered.', $server->name));
        }

        $this->servers[$server->name] = $server;

        return $this;
    }

    /** @param callable(SupervisorEvent): void $listener */
    public function onEvent(callable $listener): self
    {
        $closure = Closure::fromCallable($listener);
        $this->lifecycleListeners[] = $closure;
        $this->supervisor?->onEvent($closure);

        return $this;
    }

    public function recycle(string $serverName, int $slot): bool
    {
        $server = $this->servers[$serverName] ?? null;
        if ($server === null) {
            throw new LogicException(sprintf('Unknown server "%s".', $serverName));
        }
        if ($this->supervisor === null) {
            return false;
        }

        $recycled = $this->supervisor->recycle(self::serverGroupName($server), $slot);
        if (!$server instanceof Server || $server->http3 === null) {
            return $recycled;
        }

        $http3Recycled = $this->supervisor->recycle(self::http3GroupName($server), $slot);

        return $recycled && $http3Recycled;
    }

    public function reload(): void
    {
        $this->supervisor?->reload();
    }

    public function run(): void
    {
        if ($this->started) {
            throw new LogicException('A Runtime instance can only be run once.');
        }
        if ($this->servers === []) {
            throw new LogicException('At least one server must be registered before run().');
        }

        $this->started = true;
        $this->selection = $this->selector->select($this->options, $this->environmentProbe->probe());
        if ($this->selection->driver !== RuntimeDriver::NATIVE) {
            throw new RuntimeUnavailableException(sprintf(
                'Runtime driver "%s" is host-owned; use Runtime::serve() without Runwire listeners.',
                $this->selection->driver->value,
            ));
        }
        $this->assertNativeHttp3Available();

        $bound = $this->bindServers();

        try {
            $this->supervisor = $this->buildSupervisor($bound);
            $this->supervisor->run();
        } finally {
            foreach ($bound as $target) {
                self::closeBound($target, true);
            }
            $this->supervisor = null;
        }
    }

    public function selection(): ?RuntimeSelection
    {
        return $this->selection;
    }

    /**
     * @param callable(\Infocyph\Runwire\Http\HttpRequest, \Infocyph\Runwire\Http\ResponseWriterInterface): void $handler
     * @param callable(): void|null $requestCleanup
     * @param callable(): void|null $shutdown
     */
    public function serve(callable $handler, ?callable $requestCleanup = null, ?callable $shutdown = null): void
    {
        if ($this->started) {
            throw new LogicException('A Runtime instance can only be run once.');
        }
        if ($this->servers !== []) {
            throw new LogicException('Host-owned serve() cannot be combined with Runwire listeners.');
        }
        if ($this->controlOptions !== null || $this->lifecycleListeners !== []) {
            throw new LogicException('Host-owned serve() cannot use the native supervisor control/event plane.');
        }

        $this->started = true;
        $this->selection = $this->selector->select($this->options, $this->environmentProbe->probe());
        if ($this->selection->driver === RuntimeDriver::NATIVE) {
            throw new RuntimeUnavailableException('The native runtime owns its listeners; configure listen() and call run().');
        }

        $this->hostDriver = new HostDriverFactory()->create($this->selection->driver, $this->options);
        $this->hostApplication = new RuntimeApplication(
            $handler,
            $requestCleanup,
            $shutdown,
            $this->hostRuntimeContext(),
            $this->options->requestExecution,
            $this->options->applicationLifecycle,
        );

        try {
            $this->hostDriver->run($this->hostApplication);
        } finally {
            $this->hostApplication = null;
            $this->hostDriver = null;
        }
    }

    public function status(): ?SupervisorStatus
    {
        return $this->supervisor?->status();
    }

    public function stop(bool $force = false): void
    {
        $this->hostApplication?->drain();
        $this->hostDriver?->stop();
        $this->supervisor?->stop($force);
    }

    private static function closeBound(
        BoundServer|BoundStreamServer|BoundDatagramServer $target,
        bool $master,
    ): void {
        if ($target instanceof BoundStreamServer && $target->listener instanceof UnixListener) {
            $target->listener->close($master);

            return;
        }
        $target->listener->close();
    }

    private static function groupPrefix(BoundServer|BoundStreamServer|BoundDatagramServer $target): string
    {
        return match (true) {
            $target instanceof BoundServer => 'http',
            $target instanceof BoundStreamServer => $target->definition->transport->value,
            $target instanceof BoundDatagramServer => 'udp',
        };
    }

    private static function http3GroupName(Server $server): string
    {
        return 'http3:' . $server->name;
    }

    private static function serverGroupName(Server|StreamServer|DatagramServer $server): string
    {
        $prefix = match (true) {
            $server instanceof Server => 'http',
            $server instanceof StreamServer => $server->transport->value,
            $server instanceof DatagramServer => 'udp',
        };

        return $prefix . ':' . $server->name;
    }

    private static function workerListenerOptions(ListenerOptions $options, int $workerLimit): ListenerOptions
    {
        return new ListenerOptions(
            backlog: $options->backlog,
            maxConnections: min($options->maxConnections, $workerLimit),
            acceptBatchSize: $options->acceptBatchSize,
            socketContext: $options->socketContext,
        );
    }

    private static function workerUnixOptions(StreamServer $server): UnixListenerOptions
    {
        $options = $server->unix ?? new UnixListenerOptions(listener: $server->listener);

        return new UnixListenerOptions(
            listener: self::workerListenerOptions($options->listener, $server->workerConnectionLimit),
            removeStaleSocket: $options->removeStaleSocket,
            permissions: $options->permissions,
            unlinkOnClose: $options->unlinkOnClose,
        );
    }

    private function assertNativeHttp3Available(): void
    {
        foreach ($this->servers as $server) {
            if (!$server instanceof Server || $server->http3 === null) {
                continue;
            }
            if ($this->selection?->capabilities->supportsQuic !== true) {
                throw new RuntimeUnavailableException(
                    'Native HTTP/3 is configured, but the required QUIC runtime capability is unavailable.',
                );
            }
        }
    }

    /** @return array<string, BoundServer|BoundStreamServer|BoundDatagramServer> */
    private function bindServers(): array
    {
        $bound = [];

        try {
            foreach ($this->servers as $name => $server) {
                $bound[$name] = match (true) {
                    $server instanceof Server => new BoundServer(
                        $server,
                        TcpListener::bind($server->address, self::workerListenerOptions($server->listener, $server->workerConnectionLimit), $server->connection, $server->tls),
                    ),
                    $server instanceof StreamServer => $this->bindStreamServer($server),
                    $server instanceof DatagramServer => new BoundDatagramServer(
                        $server,
                        DatagramListener::bind($server->address, $server->options),
                    ),
                };
            }

            return $bound;
        } catch (\Throwable $error) {
            foreach ($bound as $target) {
                self::closeBound($target, true);
            }

            throw $error;
        }
    }

    private function bindStreamServer(StreamServer $server): BoundStreamServer
    {
        $listener = match ($server->transport) {
            StreamTransport::TCP => TcpListener::bind(
                $server->address,
                self::workerListenerOptions($server->listener, $server->workerConnectionLimit),
                $server->connection,
                $server->tls,
            ),
            StreamTransport::UNIX => UnixListener::bind(
                $server->address,
                self::workerUnixOptions($server),
                $server->connection,
            ),
        };

        return new BoundStreamServer($server, $listener);
    }

    /** @param array<string, BoundServer|BoundStreamServer|BoundDatagramServer> $bound */
    private function buildSupervisor(array $bound): Supervisor
    {
        $supervisor = new Supervisor(reloadPolicy: $this->options->reload);
        if ($this->controlOptions !== null) {
            $supervisor->control($this->controlOptions);
        }
        foreach ($this->lifecycleListeners as $listener) {
            $supervisor->onEvent($listener);
        }
        foreach ($bound as $name => $target) {
            $definition = $target->definition;
            $supervisor->group(WorkerGroup::callbacks(
                name: self::groupPrefix($target) . ':' . $name,
                count: $definition->workers,
                factory: function (WorkerContext $context) use ($bound, $target): void {
                    foreach ($bound as $candidate) {
                        if ($candidate !== $target) {
                            self::closeBound($candidate, false);
                        }
                    }
                    match (true) {
                        $target instanceof BoundServer => NativeHttpWorker::run(
                            $context,
                            $target,
                            $this->nativeRuntimeContext($context),
                            $this->options->requestExecution,
                            $this->options->applicationLifecycle,
                        ),
                        $target instanceof BoundStreamServer => NativeStreamWorker::run($context, $target),
                        $target instanceof BoundDatagramServer => NativeDatagramWorker::run($context, $target),
                    };
                },
                recyclePolicy: $this->options->workerRecycle,
                automaticReady: false,
                readyTimeoutSeconds: $definition->workerReadyTimeoutSeconds,
                shutdownTimeoutSeconds: $definition->workerShutdownTimeoutSeconds,
            ));
            if ($target instanceof BoundServer && $target->definition->http3 !== null) {
                $this->registerHttp3Group($supervisor, $bound, $target);
            }
        }

        return $supervisor;
    }

    private function hostRuntimeContext(): RuntimeContext
    {
        $selection = $this->selection ?? throw new LogicException('Runtime selection is unavailable before startup.');
        $capabilities = $selection->capabilities;
        $mode = match ($selection->driver) {
            RuntimeDriver::FPM => 'request',
            RuntimeDriver::FRANKENPHP => $capabilities->persistentApplication ? 'worker' : 'classic',
            RuntimeDriver::ROADRUNNER, RuntimeDriver::SWOOLE => 'worker',
            RuntimeDriver::AUTO, RuntimeDriver::NATIVE => throw new LogicException('Host runtime context requires a host-owned driver.'),
        };

        return RuntimeContext::fromCapabilities(
            $capabilities,
            $mode,
            concurrent: $capabilities->supportsCoroutines,
        );
    }

    private function nativeRuntimeContext(WorkerContext $context): RuntimeContext
    {
        $selection = $this->selection ?? throw new LogicException('Runtime selection is unavailable before startup.');

        return RuntimeContext::fromCapabilities(
            $selection->capabilities,
            'native',
            workerSlot: $context->slot,
            generation: $context->generation,
            pid: $context->pid,
            concurrent: false,
        );
    }

    /** @param array<string, BoundServer|BoundStreamServer|BoundDatagramServer> $bound */
    private function registerHttp3Group(Supervisor $supervisor, array $bound, BoundServer $target): void
    {
        $definition = $target->definition;
        $tcpAddress = $target->listener->address();
        $supervisor->group(WorkerGroup::callbacks(
            name: self::http3GroupName($definition),
            count: $definition->workers,
            factory: function (WorkerContext $context) use ($bound, $definition, $tcpAddress): void {
                foreach ($bound as $candidate) {
                    self::closeBound($candidate, false);
                }
                NativeHttp3Worker::run(
                    $context,
                    $definition,
                    $tcpAddress,
                    $this->nativeRuntimeContext($context),
                    $this->options->requestExecution,
                    $this->options->applicationLifecycle,
                );
            },
            recyclePolicy: $this->options->workerRecycle,
            automaticReady: false,
            readyTimeoutSeconds: $definition->workerReadyTimeoutSeconds,
            shutdownTimeoutSeconds: $definition->workerShutdownTimeoutSeconds,
        ));
    }
}
