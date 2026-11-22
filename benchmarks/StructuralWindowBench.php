<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Benchmarks;

use Infocyph\Runwire\Interop\Psr\PsrMiddlewareAdapter;
use Infocyph\Runwire\Interop\Psr\PsrMiddlewareHandlerLayer;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Matched, same-process comparison of the PSR-15 chain construction strategies.
 *
 * The reference subject reproduces the pre-B02 rebuild-on-every-request loop.
 * These are host-side synchronous microbenchmarks, not HTTP throughput certification.
 * Keep the same request, middleware objects, and terminal handler for both subjects.
 */
#[Iterations(5)]
#[Revs(1_000)]
#[Warmup(1)]
final class StructuralWindowBench
{
    private PsrMiddlewareAdapter $adapter;

    /** @var list<MiddlewareInterface> */
    private array $middleware;

    private ServerRequestInterface $request;

    private RequestHandlerInterface $terminal;

    /** @throws \InvalidArgumentException */
    public function __construct()
    {
        $this->request = new ServerRequest('GET', 'https://benchmark.example.test/');
        $this->terminal = new class implements RequestHandlerInterface {
            private readonly ResponseInterface $response;

            public function __construct()
            {
                $this->response = new Response(204);
            }

            /** @throws \LogicException */
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if ($request->getMethod() !== 'GET') {
                    throw new \LogicException('Benchmark request method unexpectedly changed.');
                }

                return $this->response;
            }
        };
        $passThrough = new class implements MiddlewareInterface {
            #[\Override]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };
        $this->middleware = array_fill(0, 32, $passThrough);
        $this->adapter = new PsrMiddlewareAdapter($this->middleware, $this->terminal);
    }

    /**
     * Separately measure adapter initialization, which now builds the chain once.
     * @throws \InvalidArgumentException
     */
    public function benchPsrPrebuiltConstruction(): int
    {
        $adapter = new PsrMiddlewareAdapter($this->middleware, $this->terminal);

        return $adapter->handle($this->request)->getStatusCode();
    }

    /** Current B02: handle requests using the already constructed chain. */
    public function benchPsrPrebuiltHandle(): int
    {
        return $this->adapter->handle($this->request)->getStatusCode();
    }

    /** Reference B01: rebuild the same chain for every request. */
    public function benchPsrReferenceRebuild(): int
    {
        $handler = $this->terminal;
        for ($index = count($this->middleware) - 1; $index >= 0; --$index) {
            $handler = new PsrMiddlewareHandlerLayer($this->middleware[$index], $handler);
        }

        return $handler->handle($this->request)->getStatusCode();
    }
}
