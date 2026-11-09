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
    /** @var ArrayObject<int,string> $order */
    $order = new ArrayObject();
    $middleware = new class($order) implements MiddlewareInterface {
        /** @param ArrayObject<int,string> $order */
        public function __construct(private readonly ArrayObject $order) {}
        /**
         * @throws \InvalidArgumentException
         */
        #[\Override]
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $this->order->append('before');
            $result = $handler->handle($request->withAttribute('checked', true));
            $this->order->append('after');

            return $result->withHeader('x-runwire-psr15', 'ok');
        }
    };
    $terminal = new class($order) implements RequestHandlerInterface {
        /** @param ArrayObject<int,string> $order */
        public function __construct(private readonly ArrayObject $order) {}
        #[\Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->order->append('handler');

            return new Response($request->getAttribute('checked') === true ? 202 : 403);
        }
    };
    $response = (new PsrMiddlewareAdapter([$middleware], $terminal))->handle(
        new ServerRequest('GET', 'https://example.test/'),
    );
    expect($response->getStatusCode())->toBe(202)
        ->and($response->getHeaderLine('x-runwire-psr15'))->toBe('ok')
        ->and(iterator_to_array($order))->toBe(['before', 'handler', 'after']);
});

it('fails closed on more than 32 PSR-15 middleware rather than recursing without bound', function (): void {
    $middleware = new class implements MiddlewareInterface {
        #[\Override]
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            return $handler->handle($request);
        }
    };
    $terminal = new class implements RequestHandlerInterface {
        #[\Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response($request->getMethod() === 'GET' ? 200 : 405);
        }
    };
    expect(fn() => new PsrMiddlewareAdapter(array_fill(0, 33, $middleware), $terminal))
        ->toThrow(InvalidArgumentException::class);
});
