<?php

declare(strict_types=1);

use Infocyph\Runwire\Interop\Psr\PsrMiddlewareAdapter;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

it('composes ordered PSR-15 middleware and preserves host-owned request metadata', function (): void {
    $order = [];
    $middleware = new class($order) implements MiddlewareInterface {
        public function __construct(private array &$order) {}
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $this->order[] = 'before';
            $result = $handler->handle($request->withAttribute('checked', true));
            $this->order[] = 'after';

            return $result->withHeader('x-runwire-psr15', 'ok');
        }
    };
    $terminal = new class($order) implements RequestHandlerInterface {
        public function __construct(private array &$order) {}
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->order[] = 'handler';

            return new Response($request->getAttribute('checked') === true ? 202 : 403);
        }
    };
    $response = (new PsrMiddlewareAdapter([$middleware], $terminal))->handle(
        new ServerRequest('GET', 'https://example.test/'),
    );
    expect($response->getStatusCode())->toBe(202)
        ->and($response->getHeaderLine('x-runwire-psr15'))->toBe('ok')
        ->and($order)->toBe(['before', 'handler', 'after']);
});

it('fails closed on more than 32 PSR-15 middleware rather than recursing without bound', function (): void {
    $middleware = new class implements MiddlewareInterface {
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            return $handler->handle($request);
        }
    };
    $terminal = new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response($request->getMethod() === 'GET' ? 200 : 405);
        }
    };
    expect(fn() => new PsrMiddlewareAdapter(array_fill(0, 33, $middleware), $terminal))
        ->toThrow(InvalidArgumentException::class);
});
