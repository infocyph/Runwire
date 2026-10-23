<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\RequestDeadline;
use InvalidArgumentException;

/** Resource, timeout and socket policy for an outbound connection attempt. */
final readonly class DialOptions
{
    /** Create and validate a finite per-attempt connection timeout and optional inherited controls. */
    public function __construct(
        public float $connectTimeoutSeconds = 10.0,
        public ?RequestDeadline $deadline = null,
        public ?CancellationToken $cancellation = null,
        public ?ConnectionLimits $connectionLimits = null,
        public ?bool $tcpNoDelay = null,
        public ?TlsClientOptions $tls = null,
    ) {
        if (!is_finite($connectTimeoutSeconds) || $connectTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('Connect timeout must be finite and positive.');
        }
    }
}
