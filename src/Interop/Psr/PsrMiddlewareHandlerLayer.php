<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Interop\Psr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal
 *
 * Immutable PSR-15 invocation layer for host-owned synchronous execution.
 * Each layer owns exactly one middleware and the next request handler.
 */
final readonly class PsrMiddlewareHandlerLayer implements RequestHandlerInterface
{
    /** Own the verified middleware and immutable following handler. */
    public function __construct(
        private MiddlewareInterface $middleware,
        private RequestHandlerInterface $next,
    ) {}

    /** Delegate one request through this middleware without reactor adaptation. */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->middleware->process($request, $this->next);
    }
}
