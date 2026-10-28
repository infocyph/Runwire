<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Interop\Psr;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Optional PSR-15 middleware chain for a host-owned synchronous request context.
 *
 * Never run user-supplied synchronous PSR middleware on the native Runwire reactor.
 * Bridge runtime-owned request/response streams through an independently audited
 * host adapter rather than reading blocking PSR streams in network callbacks.
 */
final readonly class PsrMiddlewareAdapter implements RequestHandlerInterface
{
    /** @param list<MiddlewareInterface> $middleware */
    public function __construct(
        private array $middleware,
        private RequestHandlerInterface $terminal,
        private int $index = 0,
    ) {
        if ($index < 0 || $index > count($middleware) || count($middleware) > 32) {
            throw new InvalidArgumentException('PSR-15 middleware count or cursor exceeds its bounded limit.');
        }
        if (array_filter($middleware, static fn(mixed $part): bool => !$part instanceof MiddlewareInterface) !== []) {
            throw new InvalidArgumentException('Every PSR-15 middleware must implement MiddlewareInterface.');
        }
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $middleware = $this->middleware[$this->index] ?? null;
        if ($middleware === null) {
            return $this->terminal->handle($request);
        }

        return $middleware->process($request, new self($this->middleware, $this->terminal, $this->index + 1));
    }
}
