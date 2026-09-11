<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Closure;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Network\DatagramListener;
use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Network\UnixListener;
use Infocyph\Runwire\Network\UnixListenerOptions;
use Infocyph\Runwire\Runtime\Internal\BoundDatagramServer;
use Infocyph\Runwire\Runtime\Internal\BoundServer;
use Infocyph\Runwire\Runtime\Internal\BoundStreamServer;
use Infocyph\Runwire\Runtime\Internal\NativeDatagramWorker;
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
    /** @var array<string, Server|StreamServer|DatagramServer> */
    private array $servers = [];
    private bool $started = false;
    private ?Supervisor $supervisor = null;
    private ?RuntimeSelection $selection = null;

    /** @var list<Closure(SupervisorEvent): void> */
    private array $lifecycleListeners = [];

    private function __construct(
        private readonly RuntimeOptions $options,
        private readonly RuntimeEnvironmentProbe $environmentProbe,
        private readonly RuntimeSelector $selector,
    ) {
    }

    public static function create(?RuntimeOptions $options = null): self
    {
        return new self(
            $options ?? new RuntimeOptions(),
            new RuntimeEnvironmentProbe(),
            new RuntimeSelector(),
        );
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

    public function selection(): ?RuntimeSelection
    {
        return $this->selection;
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

        return $this->supervisor->recycle(self::serverGroupName($server), $slot);
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
                'Runtime driver "%s" is selected, but its host adapter is not wired to Runtime::run() yet.',
                $this->selection->driver->value,
            ));
        }

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

    public function stop(bool $force = false): void
    {
        $this->supervisor?->stop($force);
    }

    public function reload(): void
    {
        $this->supervisor?->reload();
    }

    public function status(): ?SupervisorStatus
    {
        return $this->supervisor?->status();
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
                        TcpListener::bind($server->address, $server->listener, $server->connection, $server->tls),
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
                $server->listener,
                $server->connection,
                $server->tls,
            ),
            StreamTransport::UNIX => UnixListener::bind(
                $server->address,
                $server->unix ?? new UnixListenerOptions(listener: $server->listener),
                $server->connection,
            ),
        };

        return new BoundStreamServer($server, $listener);
    }

    /** @param array<string, BoundServer|BoundStreamServer|BoundDatagramServer> $bound */
    private function buildSupervisor(array $bound): Supervisor
    {
        $supervisor = new Supervisor();
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
                        $target instanceof BoundServer => NativeHttpWorker::run($context, $target),
                        $target instanceof BoundStreamServer => NativeStreamWorker::run($context, $target),
                        $target instanceof BoundDatagramServer => NativeDatagramWorker::run($context, $target),
                    };
                },
                automaticReady: false,
                readyTimeoutSeconds: $definition->workerReadyTimeoutSeconds,
                shutdownTimeoutSeconds: $definition->workerShutdownTimeoutSeconds,
            ));
        }
        return $supervisor;
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

    private static function groupPrefix(BoundServer|BoundStreamServer|BoundDatagramServer $target): string
    {
        return match (true) {
            $target instanceof BoundServer => 'http',
            $target instanceof BoundStreamServer => $target->definition->transport->value,
            $target instanceof BoundDatagramServer => 'udp',
        };
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
}
