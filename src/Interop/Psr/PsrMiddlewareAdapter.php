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
    /** The verified, immutable handler chain is constructed once per adapter. */
    private RequestHandlerInterface $handler;

    /**
     * @param array<array-key,mixed> $middleware
     * @throws \InvalidArgumentException
     */
    public function __construct(
        array $middleware,
        RequestHandlerInterface $terminal,
    ) {
        if (count($middleware) > 32 || !array_is_list($middleware)) {
            throw new InvalidArgumentException('PSR-15 middleware must be a bounded ordered list.');
        }
        foreach ($middleware as $part) {
            if (!$part instanceof MiddlewareInterface) {
                throw new InvalidArgumentException('Every PSR-15 middleware must implement MiddlewareInterface.');
            }
        }
        $handler = $terminal;
        for ($index = count($middleware) - 1; $index >= 0; --$index) {
            $handler = new PsrMiddlewareHandlerLayer($middleware[$index], $handler);
        }
        $this->handler = $handler;
    }

    /** Execute the prebuilt, bounded host-side PSR-15 middleware stack. */
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handler->handle($request);
    }
}
