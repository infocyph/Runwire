<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Driver;

use Closure;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\Host\DynamicHostObject;
use Infocyph\Runwire\Runtime\Host\HostDriverInterface;
use Infocyph\Runwire\Runtime\Internal\WorkerRecycleState;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;
use Infocyph\Runwire\SwooleOptions;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;

final class SwooleDriver implements HostDriverInterface
{
    /** @var Closure(string, int): object */
    private readonly Closure $serverFactory;

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

    public function run(RuntimeApplicationInterface $application): void
    {
        $server = ($this->serverFactory)($this->options->host, $this->options->port);
        $this->server = $server;

        try {
            $this->configure($server, $application);
            $started = DynamicHostObject::method($server, 'start')();
            if ($started === false) {
                throw new RuntimeException('Swoole/OpenSwoole server failed to start.');
            }
        } finally {
            $this->server = null;
            $application->shutdown();
        }
    }

    public function stop(): void
    {
        if ($this->server === null) {
            return;
        }

        DynamicHostObject::method($this->server, 'shutdown')();
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
            self::configureOpenSwooleFiberContext();
            $class = self::serverClass();
            $reflection = new ReflectionClass($class);

            return $reflection->newInstance($host, $port);
        };
    }

    private static function configureOpenSwooleFiberContext(): void
    {
        $version = phpversion('openswoole');
        if (!is_string($version) || version_compare($version, '26.2.0', '<')) {
            return;
        }

        $coroutineClass = 'OpenSwoole\\Coroutine';
        if (!class_exists($coroutineClass)) {
            return;
        }

        try {
            $set = new ReflectionMethod($coroutineClass, 'set');
        } catch (ReflectionException) {
            return;
        }
        $set->invoke(null, [
            'use_fiber_context' => true,
        ]);
    }

    private static function protocolVersion(string $protocol): ProtocolVersion
    {
        return match (strtoupper($protocol)) {
            'HTTP/2', 'HTTP/2.0' => ProtocolVersion::HTTP_2,
            'HTTP/3', 'HTTP/3.0' => ProtocolVersion::HTTP_3,
            default => ProtocolVersion::HTTP_1_1,
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

    private function configure(object $server, RuntimeApplicationInterface $application): void
    {
        $configured = DynamicHostObject::method($server, 'set')($this->options->serverSettings($this->recyclePolicy));
        if ($configured === false) {
            throw new RuntimeException('Swoole/OpenSwoole rejected the configured server settings.');
        }

        $recycleState = null;
        DynamicHostObject::method($server, 'on')(
            'WorkerStart',
            function () use ($application, &$recycleState): void {
                $application->start();
                $recycleState = new WorkerRecycleState($this->recyclePolicy);
            },
        );
        DynamicHostObject::method($server, 'on')(
            'WorkerStop',
            static function () use ($application): void {
                $application->drain();
                $application->shutdown();
            },
        );

        $registered = DynamicHostObject::method($server, 'on')(
            'Request',
            function (object $request, object $response) use ($application, $server, &$recycleState): void {
                $recycleState ??= new WorkerRecycleState($this->recyclePolicy);
                $normalized = $this->request($request);
                $completed = false;

                try {
                    $application->handle($normalized, $this->writer($response, $normalized->method));
                    $completed = true;
                } finally {
                    if ($recycleState->recordRequestCompleted(enforceRequestLimit: false)) {
                        $stopped = DynamicHostObject::method($server, 'stop')(-1, true);
                        if ($stopped === false && $completed) {
                            throw new RuntimeException('Swoole/OpenSwoole failed to recycle the current worker.');
                        }
                    }
                }
            },
        );
        if ($registered === false) {
            throw new RuntimeException('Swoole/OpenSwoole rejected the HTTP request callback.');
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
            version: self::protocolVersion(self::serverString($server, 'server_protocol', 'HTTP/1.1')),
            headers: Headers::fromArray(self::headers(DynamicHostObject::arrayProperty($request, 'header'))),
            body: new BufferedRequestBody($body),
            peerAddress: self::address($server, 'remote_addr', 'remote_port'),
            localAddress: self::address($server, 'server_addr', 'server_port'),
            encrypted: self::encrypted($server),
        );
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
