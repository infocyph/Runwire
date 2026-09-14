<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * Adapts RoadRunner worker objects to Runwire's host session contract.
 */
final readonly class RoadRunnerSession implements RoadRunnerSessionInterface
{
    /**
     * Creates a session around RoadRunner worker and HTTP worker objects.
     */
    public function __construct(
        private object $worker,
        private object $httpWorker,
    ) {}

    /**
     * Creates a session from the installed RoadRunner worker runtime.
     */
    public static function create(): self
    {
        $workerClass = self::hostClass('Spiral', 'RoadRunner', 'Worker');
        $httpWorkerClass = self::hostClass('Spiral', 'RoadRunner', 'Http', 'HttpWorker');
        if (!class_exists($workerClass) || !class_exists($httpWorkerClass)) {
            throw new RuntimeUnavailableException(
                'The RoadRunner host driver requires spiral/roadrunner-http and its worker runtime.',
            );
        }

        $worker = new ReflectionClass($workerClass)->getMethod('create')->invoke(null);
        if (!is_object($worker)) {
            throw new RuntimeUnavailableException('Unable to create the RoadRunner worker.');
        }

        return new self(
            $worker,
            new ReflectionClass($httpWorkerClass)->newInstance($worker),
        );
    }

    /**
     * Sends one HTTP response chunk through RoadRunner.
     */
    public function respond(int $status, string $body, array $headers, bool $endOfStream): void
    {
        DynamicHostObject::method($this->httpWorker, 'respond')($status, $body, $headers, $endOfStream);
    }

    /**
     * Stops the underlying RoadRunner worker.
     */
    public function stop(): void
    {
        DynamicHostObject::method($this->worker, 'stop')();
    }

    /**
     * Waits for and normalizes the next RoadRunner HTTP request.
     */
    public function waitRequest(int $maxRequestBodyBytes): ?HttpRequest
    {
        if ($maxRequestBodyBytes < 1) {
            throw new InvalidArgumentException('Maximum RoadRunner request body size must be positive.');
        }

        $request = DynamicHostObject::method($this->httpWorker, 'waitRequest')();
        if ($request === null) {
            return null;
        }
        if (!is_object($request)) {
            throw new RuntimeException('RoadRunner waitRequest() must return an object or null.');
        }

        return self::normalizeRequest($request, $maxRequestBodyBytes);
    }

    /** @return array<string, string|list<string>> */
    private static function headers(object $request): array
    {
        $headers = [];
        foreach (DynamicHostObject::arrayProperty($request, 'headers') as $name => $values) {
            if ($name === '') {
                continue;
            }
            if (is_string($values)) {
                $headers[strtolower($name)] = $values;

                continue;
            }
            if (!is_array($values)) {
                continue;
            }

            $list = [];
            foreach ($values as $value) {
                if (is_string($value)) {
                    $list[] = $value;
                }
            }
            if ($list !== []) {
                $headers[strtolower($name)] = $list;
            }
        }

        return $headers;
    }

    private static function hostClass(string ...$parts): string
    {
        return implode('\\', $parts);
    }

    private static function normalizeRequest(object $request, int $maxRequestBodyBytes): HttpRequest
    {
        $body = self::stringProperty($request, 'body');
        if (strlen($body) > $maxRequestBodyBytes) {
            throw new InvalidArgumentException('RoadRunner request body exceeds the configured limit.');
        }

        $uri = self::stringProperty($request, 'uri');
        $remoteAddress = DynamicHostObject::method($request, 'getRemoteAddr')();

        return new HttpRequest(
            method: self::stringProperty($request, 'method'),
            target: self::target($uri),
            version: self::protocolVersion(self::stringProperty($request, 'protocol')),
            headers: Headers::fromArray(self::headers($request)),
            body: new BufferedRequestBody($body),
            peerAddress: is_string($remoteAddress) && $remoteAddress !== '' ? $remoteAddress : null,
            encrypted: str_starts_with(strtolower($uri), 'https://'),
        );
    }

    private static function protocolVersion(string $protocol): ProtocolVersion
    {
        return match (strtoupper($protocol)) {
            'HTTP/2', 'HTTP/2.0' => ProtocolVersion::HTTP_2,
            'HTTP/3', 'HTTP/3.0' => ProtocolVersion::HTTP_3,
            default => ProtocolVersion::HTTP_1_1,
        };
    }

    private static function stringProperty(object $object, string $property): string
    {
        if (!property_exists($object, $property)) {
            throw new RuntimeException(sprintf('RoadRunner request is missing property %s.', $property));
        }

        $value = new ReflectionProperty($object, $property)->getValue($object);
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('RoadRunner request property %s must be a string.', $property));
        }

        return $value;
    }

    private static function target(string $uri): string
    {
        if ($uri === '*' || str_starts_with($uri, '/')) {
            return $uri;
        }

        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'])) {
            return $uri;
        }

        $path = $parts['path'] ?? '/';
        $target = $path !== '' ? $path : '/';
        $query = $parts['query'] ?? null;
        if ($query !== null && $query !== '') {
            $target .= '?' . $query;
        }

        return $target;
    }
}
