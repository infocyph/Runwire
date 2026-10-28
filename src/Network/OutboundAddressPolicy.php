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
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('Outbound address must be a validated numeric IP.');
        }

        $packed = inet_pton($ip);
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

        // Some kernels report an IPv4 peer through an IPv4-mapped IPv6
        // address. Match the same 32-bit endpoint, never merely the textual IP.
        if (self::canonicalAddress($approvedIp) !== self::canonicalAddress($peer->host)) {
            throw new RuntimeException('Connected peer differs from the authorized resolved IP.');
        }
    }

    private static function canonicalAddress(string $ip): string
    {
        $packed = inet_pton($ip);
        if (!is_string($packed)) {
            throw new RuntimeException('Connected peer must have a valid numeric IP.');
        }

        return strlen($packed) === 16
            && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff"
                ? substr($packed, 12)
                : $packed;
    }
}
