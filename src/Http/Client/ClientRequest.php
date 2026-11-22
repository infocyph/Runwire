<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Http\Client\Internal\PendingOutboundUri;
use Infocyph\Runwire\Http\Enum\OutboundUriSource;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\RequestDeadline;
use InvalidArgumentException;

/** Immutable outbound request envelope; incremental uploads use a body source. */
final readonly class ClientRequest
{
    /** The requested URI. Use uriForSend() at outbound transport boundaries. */
    public string $uri;

    /** The caller-owned URI is absent while a PSR destination awaits authorization. */
    private ?string $suppliedUri;

    /**
     * Validate an absolute origin, method token, streaming body and explicit protocol policy.
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public string $method,
        string|PendingOutboundUri $uri,
        public Headers $headers = new Headers(),
        public ?ClientBodySourceInterface $body = null,
        public ClientProtocolPolicy $protocol = new ClientProtocolPolicy(),
        public ?CancellationToken $cancellation = null,
        public ?RequestDeadline $deadline = null,
        public OutboundUriSource $uriSource = OutboundUriSource::DIRECT,
    ) {
        $this->suppliedUri = is_string($uri) ? $uri : null;
        $uri = $uri instanceof PendingOutboundUri ? $uri->value : $uri;
        $this->uri = $uri;
        if (preg_match("/^[!#$%&'*+.^_\x60|~0-9A-Za-z-]+$/D", $method) !== 1) {
            throw new InvalidArgumentException('Outbound HTTP method must be a valid token.');
        }
        $parts = parse_url($uri);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['https', 'http'], true)
            || $parts['host'] === ''
            || (isset($parts['port']) && $parts['port'] === 0)
            || isset($parts['user'])
            || isset($parts['pass'])
            || preg_match('/[\\x00-\\x20\\x7F]/', $parts['host']) === 1
            || str_contains($uri, "\r")
            || str_contains($uri, "\n")
            || str_contains($uri, '#')) {
            throw new InvalidArgumentException('Outbound HTTP URI must use an absolute http(s) origin without fragment or credentials.');
        }
        if (strtolower($parts['scheme']) === 'http' && !$protocol->allowPlaintext) {
            throw new InvalidArgumentException('Plaintext HTTP URI requires an explicit client protocol policy opt-in.');
        }
    }

    /**
     * @internal Carry the destination requirement to each redirect target.
     * @throws \InvalidArgumentException
     */
    public function redirected(string $uri, Headers $headers): self
    {
        $requiresApproval = $this->suppliedUri === null
            || $this->uriSource === OutboundUriSource::PSR_APPROVAL_MATCH;

        return new self(
            method: $this->method,
            uri: $requiresApproval ? new PendingOutboundUri($uri) : $uri,
            headers: $headers,
            protocol: $this->protocol,
            cancellation: $this->cancellation,
            deadline: $this->deadline,
            uriSource: OutboundUriSource::REDIRECT,
        );
    }

    /**
     * Resolve the current destination before a dial or send on an existing transport.
     * @throws \InvalidArgumentException
     */
    public function uriForSend(): string
    {
        if ($this->protocol->destinationPolicy !== null) {
            return $this->protocol->destinationPolicy->authorize($this->uri);
        }
        if ($this->suppliedUri === null) {
            throw new InvalidArgumentException('PSR outbound requests need an approved URI or a configured destination policy.');
        }

        return $this->suppliedUri;
    }

    /**
     * @internal Give a client-owned copy its linked cancellation token.
     * @throws \InvalidArgumentException
     */
    public function withCancellation(CancellationToken $cancellation): self
    {
        return new self(
            $this->method,
            $this->suppliedUri ?? new PendingOutboundUri($this->uri),
            $this->headers,
            $this->body,
            $this->protocol,
            $cancellation,
            $this->deadline,
            $this->uriSource,
        );
    }
}
