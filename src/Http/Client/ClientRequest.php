<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\RequestDeadline;
use InvalidArgumentException;

/** Immutable outbound request envelope; incremental uploads use a body source. */
final readonly class ClientRequest
{
    /** Validate an absolute origin, method token, streaming body and explicit protocol policy. */
    public function __construct(
        public string $method,
        public string $uri,
        public Headers $headers = new Headers(),
        public ?ClientBodySourceInterface $body = null,
        public ClientProtocolPolicy $protocol = new ClientProtocolPolicy(),
        public ?CancellationToken $cancellation = null,
        public ?RequestDeadline $deadline = null,
    ) {
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
}
