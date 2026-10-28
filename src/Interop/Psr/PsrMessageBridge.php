<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Interop\Psr;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\RequestDeadline;
use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Opt-in PSR-7/17 metadata adapter; the Runwire runtime has no required PSR dependencies.
 * PSR stream methods may block: do not read them on the Runwire reactor.
 */
final readonly class PsrMessageBridge
{
    /** Convert bounded PSR-7 HTTP header metadata without consuming the body stream. */
    public static function headers(MessageInterface $message): Headers
    {
        $headers = $message->getHeaders();
        $count = 0;
        $bytes = 0;
        foreach ($headers as $name => $values) {
            $count += count($values);
            foreach ($values as $value) {
                $bytes += strlen((string) $name) + strlen($value) + 4;
            }
        }
        if ($count > 100 || $bytes > 65_536) {
            throw new InvalidArgumentException('PSR HTTP header count or size exceeded bounded Runwire limits.');
        }

        $normalized = [];
        foreach ($headers as $name => $values) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('PSR header names must be strings.');
            }
            $normalized[$name] = array_values($values);
        }

        return Headers::fromArray($normalized);
    }

    /** Safely adapt a bodyless PSR-7 request without a synchronous stream read. */
    public static function outboundRequest(
        RequestInterface $request,
        ?ClientProtocolPolicy $policy = null,
        ?CancellationToken $cancellation = null,
        ?RequestDeadline $deadline = null,
    ): ClientRequest {
        if ($request->getBody()->getSize() !== 0) {
            throw new RuntimeUnavailableException(
                'PSR stream reads may block the reactor; only bodyless requests are adapted directly.',
            );
        }

        return new ClientRequest(
            $request->getMethod(),
            (string) $request->getUri(),
            self::headers($request),
            protocol: $policy ?? new ClientProtocolPolicy(),
            cancellation: $cancellation,
            deadline: $deadline,
        );
    }

    /** Never consumes the body: caller must supply an appropriate off-loop stream bridge. */
    public static function requestMetadata(ClientRequest $request, RequestFactoryInterface $factory): RequestInterface
    {
        if ($request->body !== null) {
            throw new RuntimeUnavailableException('Streaming body requires a bounded off-reactor PSR stream adapter.');
        }

        $message = $factory->createRequest($request->method, $request->uri);
        foreach ($request->headers->fields() as $field) {
            $message = $message->withAddedHeader($field->name, $field->value);
        }

        return $message;
    }

    /** Creates response headers only; no synchronous PSR stream read or response execution. */
    public static function responseMetadata(int $status, Headers $headers, ResponseFactoryInterface $factory): ResponseInterface
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('Invalid HTTP response status.');
        }
        $message = $factory->createResponse($status);
        foreach ($headers->fields() as $field) {
            $message = $message->withAddedHeader($field->name, $field->value);
        }

        return $message;
    }
}
