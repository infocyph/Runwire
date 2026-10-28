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
    /** @var list<MiddlewareInterface> */
    private array $middleware;

    /** @param array<array-key,mixed> $middleware */
    public function __construct(
        array $middleware,
        private RequestHandlerInterface $terminal,
    ) {
        if (count($middleware) > 32 || !array_is_list($middleware)) {
            throw new InvalidArgumentException('PSR-15 middleware must be a bounded ordered list.');
        }
        foreach ($middleware as $part) {
            if (!$part instanceof MiddlewareInterface) {
                throw new InvalidArgumentException('Every PSR-15 middleware must implement MiddlewareInterface.');
            }
        }
        $this->middleware = $middleware;
    }

    /** Execute a bounded host-side PSR-15 middleware stack. */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handler = $this->terminal;
        for ($index = count($this->middleware) - 1; $index >= 0; --$index) {
            $middleware = $this->middleware[$index];
            $handler = new class ($middleware, $handler) implements RequestHandlerInterface {
                /** Construct an immutable next-handler for one middleware layer. */
                public function __construct(
                    private readonly MiddlewareInterface $middleware,
                    private readonly RequestHandlerInterface $next,
                ) {}

                /** Delegate to the owned middleware and next request handler. */
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }

        return $handler->handle($request);
    }
}
