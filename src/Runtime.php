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
use Infocyph\Runwire\Runtime\AdmissionPolicy;
use Infocyph\Runwire\Runtime\DevelopmentWatchPolicy;
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
use Infocyph\Runwire\Runtime\Internal\PortableNativeRuntime;
use Infocyph\Runwire\Runtime\RuntimeApplicationFactoryInterface;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\Runtime\RuntimeEnvironmentProbe;
use Infocyph\Runwire\Runtime\RuntimeSelection;
use Infocyph\Runwire\Runtime\RuntimeSelector;
use Infocyph\Runwire\Supervisor\Enum\WorkerRole;
use Infocyph\Runwire\Supervisor\Supervisor;
use Infocyph\Runwire\Supervisor\SupervisorEvent;
use Infocyph\Runwire\Supervisor\SupervisorStatus;
use Infocyph\Runwire\Supervisor\WorkerContext;
use Infocyph\Runwire\Supervisor\WorkerGroup;
use LogicException;

/**
 * Configures and runs native or host-owned Runwire application runtimes.
 */
final class Runtime
{
    private ?ControlOptions $controlOptions = null;

    private ?DevelopmentWatchPolicy $developmentWatchPolicy = null;

    private ?RuntimeApplicationInterface $hostApplication = null;

    private ?HostDriverInterface $hostDriver = null;

    /** @var list<Closure(SupervisorEvent): void> */
    private array $lifecycleListeners = [];

    private ?PortableNativeRuntime $portableRuntime = null;

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

    /**
     * Creates a runtime using the supplied or default options.
     */
    public static function create(?RuntimeOptions $options = null): self
    {
        return new self(
            $options ?? new RuntimeOptions(),
            new RuntimeEnvironmentProbe(),
            new RuntimeSelector(),
        );
    }

    /**
     * Configures the native supervisor control endpoint before startup.
     */
    public function control(ControlOptions $options): self
    {
        if ($this->started) {
            throw new LogicException('Runtime topology is frozen after run() starts.');
        }

        $this->controlOptions = $options;

        return $this;
    }

    /**
     * Registers a native HTTP, stream, or datagram server.
     */
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
        if ($this->portableRuntime !== null) {
            throw new RuntimeUnavailableException(
                'Supervisor lifecycle events are unavailable in the single-process native runtime.',
            );
        }

        $closure = Closure::fromCallable($listener);
        $this->lifecycleListeners[] = $closure;
        $this->supervisor?->onEvent($closure);

        return $this;
    }

    /**
     * Requests recycling of a server worker slot and its HTTP/3 peer when configured.
     */
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
        if (
            !$server instanceof Server
            || $server->http3 === null
            || $this->selection?->capabilities->supportsQuic !== true
        ) {
            return $recycled;
        }

        $http3Recycled = $this->supervisor->recycle(self::http3GroupName($server), $slot);

        return $recycled && $http3Recycled;
    }

    /**
     * Requests a graceful native worker reload.
     */
    public function reload(): void
    {
        if ($this->portableRuntime !== null) {
            throw new RuntimeUnavailableException(
                'Worker reload requires PCNTL/POSIX prefork capabilities and is unavailable in single-process mode.',
            );
        }

        $this->supervisor?->reload();
    }

    /**
     * Runs the configured native listener topology using prefork or portable single-process mode.
     */
    public function run(): void
    {
        if ($this->started) {
            throw new LogicException('A Runtime instance can only be run once.');
        }
        if ($this->servers === []) {
            throw new LogicException('At least one server must be registered before run().');
        }

        $this->started = true;
        $selection = $this->selector->select($this->options, $this->environmentProbe->probe());
        $this->selection = $selection;
        if ($selection->driver !== RuntimeDriver::NATIVE) {
            throw new RuntimeUnavailableException(sprintf(
                'Runtime driver "%s" is host-owned; use Runtime::serve() without Runwire listeners.',
                $selection->driver->value,
            ));
        }
        $this->assertNativeTopology();

        $bound = $this->bindServers();

        try {
            if ($selection->capabilities->ownsWorkerPool) {
                $this->supervisor = $this->buildSupervisor($bound);
                $this->supervisor->run();
            } else {
                $this->assertPortableNativeConfiguration();
                $this->portableRuntime = new PortableNativeRuntime($bound, $selection, $this->options);
                $this->portableRuntime->run();
            }
        } finally {
            foreach ($bound as $target) {
                self::closeBound($target, true);
            }
            $this->portableRuntime = null;
            $this->supervisor = null;
        }
    }

    /**
     * Returns the resolved runtime selection after startup begins.
     */
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
        $context = $this->prepareHostRuntime();
        $this->runHostApplication(new RuntimeApplication(
            $handler,
            $requestCleanup,
            $shutdown,
            $context,
            $this->options->requestExecution,
            $this->options->applicationLifecycle,
            $this->options->admission,
        ));
    }

    /**
     * Runs an application factory through the selected host-owned runtime driver.
     */
    public function serveApplication(RuntimeApplicationFactoryInterface $factory): void
    {
        $context = $this->prepareHostRuntime();
        $this->runHostApplication($factory->create($context));
    }

    /**
     * Returns the current native supervisor status when running.
     */
    public function status(): ?SupervisorStatus
    {
        return $this->supervisor?->status();
    }

    /**
     * Requests host or native runtime shutdown.
     */
    public function stop(bool $force = false): void
    {
        $this->hostApplication?->drain();
        $this->hostDriver?->stop();
        $this->portableRuntime?->stop($force);
        $this->supervisor?->stop($force);
    }

    /**
     * Configures development file watching before native runtime startup.
     */
    public function watch(DevelopmentWatchPolicy $policy): self
    {
        if ($this->started) {
            throw new LogicException('Runtime topology is frozen after run() starts.');
        }

        $this->developmentWatchPolicy = $policy;

        return $this;
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

    private static function workerListenerOptions(
        ListenerOptions $options,
        int $workerLimit,
        AdmissionPolicy $admission,
    ): ListenerOptions {
        return new ListenerOptions(
            backlog: $options->backlog,
            maxConnections: $admission->connectionLimit(min($options->maxConnections, $workerLimit)),
            acceptBatchSize: $options->acceptBatchSize,
            socketContext: $options->socketContext,
            reusePort: $options->reusePort,
        );
    }

    private static function workerUnixOptions(StreamServer $server, AdmissionPolicy $admission): UnixListenerOptions
    {
        $options = $server->unix ?? new UnixListenerOptions(listener: $server->listener);

        return new UnixListenerOptions(
            listener: self::workerListenerOptions($options->listener, $server->workerConnectionLimit, $admission),
            removeStaleSocket: $options->removeStaleSocket,
            permissions: $options->permissions,
            unlinkOnClose: $options->unlinkOnClose,
        );
    }

    private function assertNativeTopology(): void
    {
        $selection = $this->selection ?? throw new LogicException('Runtime selection is unavailable before startup.');
        if (!$selection->capabilities->supportsQuic || !$selection->capabilities->ownsWorkerPool) {
            return;
        }

        foreach ($this->servers as $server) {
            if (!$server instanceof Server || $server->http3 === null) {
                continue;
            }
            if ($this->resolvedWorkerCount($server->workers) > 1 && !$server->listener->reusePort) {
                throw new RuntimeUnavailableException(
                    'Native HTTP/3 with multiple workers requires explicit ListenerOptions::reusePort support.',
                );
            }
        }
    }

    private function assertPortableNativeConfiguration(): void
    {
        if ($this->controlOptions !== null) {
            throw new RuntimeUnavailableException(
                'The native control endpoint requires PCNTL/POSIX prefork capabilities.',
            );
        }
        if ($this->developmentWatchPolicy?->enabled === true) {
            throw new RuntimeUnavailableException(
                'Development worker watching requires PCNTL/POSIX prefork capabilities.',
            );
        }
        if ($this->lifecycleListeners !== []) {
            throw new RuntimeUnavailableException(
                'Supervisor lifecycle events require PCNTL/POSIX prefork capabilities.',
            );
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
                        TcpListener::bind(
                            $server->address,
                            self::workerListenerOptions(
                                $server->listener,
                                $server->workerConnectionLimit,
                                $this->options->admission,
                            ),
                            $server->connection,
                            $server->tls,
                        ),
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
                self::workerListenerOptions(
                    $server->listener,
                    $server->workerConnectionLimit,
                    $this->options->admission,
                ),
                $server->connection,
                $server->tls,
            ),
            StreamTransport::UNIX => UnixListener::bind(
                $server->address,
                self::workerUnixOptions($server, $this->options->admission),
                $server->connection,
            ),
        };

        return new BoundStreamServer($server, $listener);
    }

    /** @param array<string, BoundServer|BoundStreamServer|BoundDatagramServer> $bound */
    private function buildSupervisor(array $bound): Supervisor
    {
        $supervisor = new Supervisor(reloadPolicy: $this->options->reload);
        $this->configureSupervisor($supervisor);
        foreach ($bound as $name => $target) {
            $definition = $target->definition;
            $supervisor->group(WorkerGroup::callbacks(
                name: self::groupPrefix($target) . ':' . $name,
                count: $this->resolvedWorkerCount($definition->workers),
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
                            $this->options->diagnostics,
                        ),
                        $target instanceof BoundStreamServer => NativeStreamWorker::run(
                            $context,
                            $target,
                            $this->options->diagnostics,
                        ),
                        $target instanceof BoundDatagramServer => NativeDatagramWorker::run(
                            $context,
                            $target,
                            $this->options->diagnostics,
                        ),
                    };
                },
                recyclePolicy: $this->options->workerRecycle,
                admissionPolicy: $this->options->admission,
                privilegeDropPolicy: $this->options->privilegeDrop,
                automaticReady: false,
                readyTimeoutSeconds: $definition->workerReadyTimeoutSeconds,
                shutdownTimeoutSeconds: $definition->workerShutdownTimeoutSeconds,
                role: $target instanceof BoundServer ? WorkerRole::HTTP : WorkerRole::CUSTOM,
            ));
            if (
                $target instanceof BoundServer
                && $target->definition->http3 !== null
                && $this->selection?->capabilities->supportsQuic === true
            ) {
                $this->registerHttp3Group($supervisor, $bound, $target);
            }
        }

        return $supervisor;
    }

    private function configureSupervisor(Supervisor $supervisor): void
    {
        if ($this->controlOptions !== null) {
            $supervisor->control($this->controlOptions);
        }
        if ($this->developmentWatchPolicy !== null) {
            $supervisor->watch($this->developmentWatchPolicy);
        }
        foreach ($this->lifecycleListeners as $listener) {
            $supervisor->onEvent($listener);
        }
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
            concurrent: $capabilities->supportsRunwireCoroutines,
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

    private function prepareHostRuntime(): RuntimeContext
    {
        if ($this->started) {
            throw new LogicException('A Runtime instance can only be run once.');
        }
        if ($this->servers !== []) {
            throw new LogicException('Host-owned serve() cannot be combined with Runwire listeners.');
        }
        if (
            $this->controlOptions !== null
            || $this->developmentWatchPolicy?->enabled === true
            || $this->lifecycleListeners !== []
        ) {
            throw new LogicException('Host-owned serve() cannot use the native supervisor control/event/watch plane.');
        }

        $this->started = true;
        $selection = $this->selector->select($this->options, $this->environmentProbe->probe());
        $this->selection = $selection;
        if ($selection->driver === RuntimeDriver::NATIVE) {
            throw new RuntimeUnavailableException('The native runtime owns its listeners; configure listen() and call run().');
        }

        return $this->hostRuntimeContext();
    }

    /** @param array<string, BoundServer|BoundStreamServer|BoundDatagramServer> $bound */
    private function registerHttp3Group(Supervisor $supervisor, array $bound, BoundServer $target): void
    {
        $definition = $target->definition;
        $tcpAddress = $target->listener->address();
        $supervisor->group(WorkerGroup::callbacks(
            name: self::http3GroupName($definition),
            count: $this->resolvedWorkerCount($definition->workers),
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
                    $this->options->diagnostics,
                );
            },
            recyclePolicy: $this->options->workerRecycle,
            admissionPolicy: $this->options->admission,
            privilegeDropPolicy: $this->options->privilegeDrop,
            automaticReady: false,
            readyTimeoutSeconds: $definition->workerReadyTimeoutSeconds,
            shutdownTimeoutSeconds: $definition->workerShutdownTimeoutSeconds,
            role: WorkerRole::HTTP,
        ));
    }

    private function resolvedWorkerCount(int $configured): int
    {
        $selection = $this->selection ?? throw new LogicException('Runtime selection is unavailable before startup.');

        return $selection->capabilities->resources->resolveWorkerCount($configured);
    }

    private function runHostApplication(RuntimeApplicationInterface $application): void
    {
        $selection = $this->selection ?? throw new LogicException('Runtime selection is unavailable before startup.');
        $this->hostDriver = new HostDriverFactory()->create($selection->driver, $this->options);
        $this->hostApplication = $application;

        try {
            $this->hostDriver->run($application);
        } finally {
            $this->hostApplication = null;
            $this->hostDriver = null;
        }
    }
}
