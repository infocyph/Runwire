<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use RuntimeException;

/**
 * Optional fail-closed public-IP SSRF guard for outgoing TCP dials.
 *
 * Apply before opening each numeric candidate and again to the connected
 * kernel-reported peer before TLS or connection-owner handoff.
 */
final readonly class OutboundAddressPolicy
{
    /** Reject reserved, private, loopback and link-local numeric addresses. */
    public function assertCandidate(string $ip): void
    {
        $packed = @inet_pton($ip);
        if (!is_string($packed)) {
            throw new RuntimeException('Outbound address must be a validated numeric IP.');
        }

        if (strlen($packed) === 16
            && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $mapped = inet_ntop(substr($packed, 12));
            $ip = is_string($mapped) ? $mapped : '';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('Outbound address policy blocks non-public IP.');
        }
    }

    /** Verify the real connected peer has not changed from the approved candidate. */
    public function assertConnected(string $approvedIp, string $peerSocketAddress): void
    {
        $this->assertCandidate($approvedIp);
        try {
            $peer = DialEndpoint::parse('tcp://' . $peerSocketAddress);
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException('Connected peer address is not a valid numeric TCP endpoint.', 0, $exception);
        }

        if (!$peer->isIpLiteral || $peer->host === null) {
            throw new RuntimeException('Connected peer must have a numeric IP.');
        }
        $this->assertCandidate($peer->host);

        if (inet_pton($approvedIp) !== inet_pton($peer->host)) {
            throw new RuntimeException('Connected peer differs from the authorized resolved IP.');
        }
    }
}
