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

it('reuses one verified handler chain across distinct request contexts', function (): void {
    /** @var ArrayObject<int,int> $nextIds */
    $nextIds = new ArrayObject();
    $middleware = new class($nextIds) implements MiddlewareInterface {
        /** @param ArrayObject<int,int> $nextIds */
        public function __construct(private readonly ArrayObject $nextIds) {}

        #[\Override]
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $this->nextIds->append(spl_object_id($handler));

            return $handler->handle($request->withAttribute('request-id', $request->getHeaderLine('x-request-id')));
        }
    };
    $terminal = new class implements RequestHandlerInterface {
        #[\Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response(200, ['x-seen-id' => (string) $request->getAttribute('request-id', '')]);
        }
    };
    $adapter = new PsrMiddlewareAdapter([$middleware], $terminal);

    $first = $adapter->handle(new ServerRequest('GET', 'https://example.test/', ['x-request-id' => 'first']));
    $second = $adapter->handle(new ServerRequest('GET', 'https://example.test/', ['x-request-id' => 'second']));

    expect($first->getHeaderLine('x-seen-id'))->toBe('first')
        ->and($second->getHeaderLine('x-seen-id'))->toBe('second')
        ->and(iterator_to_array($nextIds))->toHaveCount(2)
        ->and($nextIds[0])->toBe($nextIds[1]);
});

it('accepts exactly 32 ordered middleware and forwards an empty stack unchanged', function (): void {
    $middleware = new class implements MiddlewareInterface {
        public int $calls = 0;

        #[\Override]
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            ++$this->calls;

            return $handler->handle($request);
        }
    };
    $terminal = new class implements RequestHandlerInterface {
        public int $calls = 0;

        #[\Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            ++$this->calls;

            return new Response($request->getMethod() === 'GET' ? 200 : 405);
        }
    };
    $request = new ServerRequest('GET', 'https://example.test/');

    expect((new PsrMiddlewareAdapter([], $terminal))->handle($request)->getStatusCode())->toBe(200)
        ->and((new PsrMiddlewareAdapter(array_fill(0, 32, $middleware), $terminal))->handle($request)->getStatusCode())->toBe(200)
        ->and($middleware->calls)->toBe(32)
        ->and($terminal->calls)->toBe(2)
        ->and(fn() => new PsrMiddlewareAdapter([1 => $middleware], $terminal))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new PsrMiddlewareAdapter([new stdClass()], $terminal))->toThrow(InvalidArgumentException::class);
});

it('preserves short-circuiting and propagates middleware exceptions', function (): void {
    $terminal = new class implements RequestHandlerInterface {
        public int $calls = 0;

        #[\Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            ++$this->calls;

            return new Response(200);
        }
    };
    $shortCircuit = new class implements MiddlewareInterface {
        #[\Override]
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            return new Response(204);
        }
    };
    $throwing = new class implements MiddlewareInterface {
        /** @throws \RuntimeException */
        #[\Override]
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            throw new RuntimeException('middleware failure');
        }
    };
    $request = new ServerRequest('GET', 'https://example.test/');

    expect((new PsrMiddlewareAdapter([$shortCircuit], $terminal))->handle($request)->getStatusCode())->toBe(204)
        ->and($terminal->calls)->toBe(0)
        ->and(fn() => (new PsrMiddlewareAdapter([$throwing], $terminal))->handle($request))
        ->toThrow(RuntimeException::class, 'middleware failure')
        ->and($terminal->calls)->toBe(0);
});
