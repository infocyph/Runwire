<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http;

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Internal\StreamingRequestBody;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

/**
 * Represents a normalized HTTP request and its runtime request context.
 */
final readonly class HttpRequest
{
    public RequestContext $context;

    /**
     * Create an HTTP request from normalized protocol, headers, body, and transport metadata.
     */
    public function __construct(
        public string $method,
        public string $target,
        public ProtocolVersion $version,
        public Headers $headers,
        public RequestBodyInterface $body,
        public ?string $peerAddress = null,
        public ?string $localAddress = null,
        public bool $encrypted = false,
        ?RequestContext $context = null,
    ) {
        $this->context = $context ?? RequestContext::standalone();

        if ($body instanceof StreamingRequestBody) {
            $requestContext = $this->context;
            $body->observeCancel(static function () use ($requestContext): void {
                $requestContext->cancel(CancellationReason::TRANSPORT_CANCELLED);
            });
        }
    }
}
