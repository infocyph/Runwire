<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Driver;

use Closure;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Host\DynamicHostObject;
use Infocyph\Runwire\Runtime\Host\HostDriverInterface;
use Infocyph\Runwire\Runtime\Host\HostProtocolVersion;
use Infocyph\Runwire\Runtime\Internal\ApplicationShutdown;
use Infocyph\Runwire\Runtime\Internal\WorkerRecycleState;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;
use Infocyph\Runwire\SwooleOptions;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Runs HTTP applications on a Swoole or OpenSwoole host server.
 */
final class SwooleDriver implements HostDriverInterface
{
    /** @var Closure(string, int): object */
    private readonly Closure $serverFactory;

    private ?RuntimeApplicationInterface $application = null;

    private bool $draining = false;

    private ?object $server = null;

    /** @param callable(string, int): object|null $serverFactory */
    public function __construct(
        private readonly SwooleOptions $options,
        ?callable $serverFactory = null,
        private readonly WorkerRecyclePolicy $recyclePolicy = new WorkerRecyclePolicy(),
    ) {
        $this->serverFactory = $serverFactory === null
            ? self::nativeServerFactory()
            : Closure::fromCallable($serverFactory);
    }

    /**
     * Configures and starts the host Swoole/OpenSwoole HTTP server.
     */
    public function run(RuntimeApplicationInterface $application): void
    {
        $this->runServer($application);
    }

    /**
     * Creates the application inside each host worker, after any fork.
     *
     * @param callable(RuntimeContext): RuntimeApplicationInterface $factory
     */
    public function runFactory(callable $factory, RuntimeContext $context): void
    {
        if ($context->driver !== RuntimeDriver::SWOOLE) {
            throw new InvalidArgumentException('Swoole/OpenSwoole application factories require a Swoole runtime context.');
        }
        $this->runServer(null, static fn(?int $workerId): RuntimeApplicationInterface => $factory(
            RuntimeContext::fromCapabilities(
                $context->capabilities,
                $context->mode,
                workerSlot: $workerId,
                generation: $context->generation,
                concurrent: $context->concurrent,
            ),
        ));
    }

    /**
     * Requests shutdown of the active Swoole/OpenSwoole server.
     */
    public function stop(): void
    {
        $server = $this->server;
        if ($server === null) {
            return;
        }

        $failure = null;

        try {
            $this->drainApplication();
        } catch (Throwable $error) {
            $failure = $error;
        }
        $shutdownFailures = [];

        try {
            DynamicHostObject::method($server, 'shutdown')();
        } catch (Throwable $error) {
            $shutdownFailures[] = $error;
        }
        ApplicationShutdown::resolve($failure, $shutdownFailures);
    }

    /** @param array<string, mixed> $server */
    private static function address(array $server, string $addressKey, string $portKey): ?string
    {
        $address = $server[$addressKey] ?? null;
        if (!is_string($address) || $address === '') {
            return null;
        }

        $port = $server[$portKey] ?? null;
        if (!is_int($port) && !is_string($port)) {
            return $address;
        }
        $portString = (string) $port;
        if ($portString === '' || preg_match('/^\d+$/D', $portString) !== 1) {
            return $address;
        }

        return str_contains($address, ':') ? sprintf('[%s]:%s', $address, $portString) : $address . ':' . $portString;
    }

    /** @param array<string, mixed> $server */
    private static function encrypted(array $server): bool
    {
        $https = $server['https'] ?? null;
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return strtolower(self::serverString($server, 'request_scheme')) === 'https';
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, string|list<string>>
     */
    private static function headers(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if ($name === '') {
                continue;
            }
            if (is_string($value) || is_int($value) || is_float($value)) {
                $normalized[strtolower($name)] = (string) $value;
            }
        }

        return $normalized;
    }

    /** @return Closure(string, int): object */
    private static function nativeServerFactory(): Closure
    {
        return static function (string $host, int $port): object {
            $class = self::serverClass();
            $reflection = new ReflectionClass($class);

            return $reflection->newInstance($host, $port);
        };
    }

    /** @return class-string */
    private static function serverClass(): string
    {
        $separator = '\\';
        $classes = [
            'OpenSwoole' . $separator . 'Http' . $separator . 'Server',
            'Swoole' . $separator . 'Http' . $separator . 'Server',
        ];

        foreach ($classes as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        throw new RuntimeUnavailableException(
            'The Swoole/OpenSwoole HTTP server class is unavailable; install ext-openswoole or ext-swoole.',
        );
    }

    /** @param array<string, mixed> $server */
    private static function serverString(array $server, string $key, string $default = ''): string
    {
        $value = $server[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** @param array<string, mixed> $server */
    private static function target(array $server): string
    {
        $target = self::serverString($server, 'request_uri', '/');
        $query = self::serverString($server, 'query_string');

        return $query === '' || str_contains($target, '?') ? $target : $target . '?' . $query;
    }

    /** @param Closure(?int): RuntimeApplicationInterface|null $factory */
    private function configure(object $server, ?Closure $factory): void
    {
        $configured = DynamicHostObject::method($server, 'set')($this->options->serverSettings($this->recyclePolicy));
        if ($configured === false) {
            throw new RuntimeException('Swoole/OpenSwoole rejected the configured server settings.');
        }

        $recycleState = null;
        DynamicHostObject::method($server, 'on')(
            'WorkerStart',
            function (?object $host = null, ?int $workerId = null) use ($factory, &$recycleState): void {
                unset($host);
                $this->startApplication($factory, $workerId);
                $recycleState = new WorkerRecycleState($this->recyclePolicy);
            },
        );
        DynamicHostObject::method($server, 'on')(
            'WorkerStop',
            function (): void {
                $failure = null;

                try {
                    $this->drainApplication();
                } catch (Throwable $error) {
                    $failure = $error;
                }
                $this->finishApplication($failure);
            },
        );

        $registered = DynamicHostObject::method($server, 'on')(
            'Request',
            function (object $request, object $response) use ($server, &$recycleState): void {
                $application = $this->application
                    ?? throw new LogicException('Swoole/OpenSwoole worker application has not started.');
                $recycleState ??= new WorkerRecycleState($this->recyclePolicy);
                $normalized = $this->request($request);
                $completed = false;

                try {
                    $application->handle(
                        $normalized,
                        $this->writer($response, $normalized->method),
                        completeResponse: true,
                    );
                    $completed = true;
                } finally {
                    $retire = $recycleState->recordRequestCompleted(enforceRequestLimit: false)
                        || !$application->healthy();
                    if ($retire) {
                        $stopped = DynamicHostObject::method($server, 'stop')(-1, true);
                        if ($stopped === false && $completed) {
                            throw new RuntimeException('Swoole/OpenSwoole failed to retire the current worker.');
                        }
                    }
                }
            },
        );
        if ($registered === false) {
            throw new RuntimeException('Swoole/OpenSwoole rejected the HTTP request callback.');
        }
    }

    private function drainApplication(): void
    {
        if ($this->application === null || $this->draining) {
            return;
        }
        $this->draining = true;
        $this->application->drain();
    }

    private function finishApplication(?Throwable $failure = null): void
    {
        $application = $this->application;
        $this->application = null;
        if ($application !== null) {
            ApplicationShutdown::finish($application, $failure);
        } elseif ($failure !== null) {
            throw $failure;
        }
    }

    private function request(object $request): HttpRequest
    {
        $server = DynamicHostObject::arrayProperty($request, 'server');
        $body = DynamicHostObject::method($request, 'rawContent')();
        if ($body === false || $body === null) {
            $body = '';
        }
        if (!is_string($body)) {
            throw new RuntimeException('Swoole/OpenSwoole request body must be a string.');
        }
        if (strlen($body) > $this->options->maxRequestBodyBytes) {
            throw new InvalidArgumentException('Host request body exceeds the configured limit.');
        }

        return new HttpRequest(
            method: self::serverString($server, 'request_method', 'GET'),
            target: self::target($server),
            version: HostProtocolVersion::from(self::serverString($server, 'server_protocol', 'HTTP/1.1')),
            headers: Headers::fromArray(self::headers(DynamicHostObject::arrayProperty($request, 'header'))),
            body: new BufferedRequestBody($body),
            peerAddress: self::address($server, 'remote_addr', 'remote_port'),
            localAddress: self::address($server, 'server_addr', 'server_port'),
            encrypted: self::encrypted($server),
        );
    }

    /** @param Closure(?int): RuntimeApplicationInterface|null $factory */
    private function runServer(?RuntimeApplicationInterface $application, ?Closure $factory = null): void
    {
        $server = ($this->serverFactory)($this->options->host, $this->options->port);
        $this->server = $server;
        $this->application = $application;
        $this->draining = false;

        $failure = null;

        try {
            $this->configure($server, $factory);
            $started = DynamicHostObject::method($server, 'start')();
            if ($started === false) {
                throw new RuntimeException('Swoole/OpenSwoole server failed to start.');
            }
        } catch (Throwable $error) {
            $failure = $error;
        } finally {
            $this->server = null;
        }

        $this->finishApplication($failure);
    }

    /** @param Closure(?int): RuntimeApplicationInterface|null $factory */
    private function startApplication(?Closure $factory, ?int $workerId): void
    {
        try {
            if ($factory !== null) {
                if ($this->application !== null) {
                    throw new LogicException('Swoole/OpenSwoole worker application is already started.');
                }
                $this->application = $factory($workerId);
            }
            $this->draining = false;
            $application = $this->application
                ?? throw new LogicException('Swoole/OpenSwoole worker application is unavailable.');
            $application->start();
        } catch (Throwable $error) {
            $this->finishApplication($error);
        }
    }

    private function writer(object $response, string $method): ResponseWriterInterface
    {
        $status = DynamicHostObject::method($response, 'status');
        $header = DynamicHostObject::method($response, 'header');
        $write = DynamicHostObject::method($response, 'write');
        $end = DynamicHostObject::method($response, 'end');

        return new CallbackResponseWriter(
            static function (int $code, Headers $headers) use ($status, $header): void {
                if ($status($code) === false) {
                    throw new RuntimeException('Swoole/OpenSwoole rejected the HTTP response status.');
                }
                foreach ($headers->fields() as $field) {
                    if ($header($field->name, $field->value) === false) {
                        throw new RuntimeException('Swoole/OpenSwoole rejected an HTTP response header.');
                    }
                }
            },
            static function (string $chunk) use ($write): void {
                if ($write($chunk) === false) {
                    throw new RuntimeException('Swoole/OpenSwoole failed to write the HTTP response body.');
                }
            },
            static function () use ($end): void {
                if ($end() === false) {
                    throw new RuntimeException('Swoole/OpenSwoole failed to finish the HTTP response.');
                }
            },
            $this->options->maxResponseBytes,
            strtoupper($method) === 'HEAD',
        );
    }
}
