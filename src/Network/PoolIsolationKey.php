<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use InvalidArgumentException;

/**
 * Collision-resistant, tenant-scoped key for future outgoing connection reuse.
 *
 * Caller-supplied security identities MUST change when credentials, trust
 * anchors, client certificates, proxy policy or tenant isolation changes.
 * No reusable connection pool is enabled merely by constructing this key.
 */
final readonly class PoolIsolationKey
{
    private string $identity;

    /**
     * Build a typed, delimiter-safe key without storing credential material.
     *
     * @param string $scopeIdentity Opaque caller-owned tenant/worker scope identifier.
     * @param string|null $tlsPolicyIdentity Revisioned identity of the full TLS/mTLS trust policy.
     * @param string|null $proxyPolicyIdentity Revisioned proxy route/authentication policy.
     */
    public function __construct(
        DialEndpoint $endpoint,
        string $scopeIdentity,
        ?TlsClientOptions $tls = null,
        ?string $tlsPolicyIdentity = null,
        ?string $negotiatedAlpn = null,
        ?DialEndpoint $proxy = null,
        ?string $proxyPolicyIdentity = null,
    ) {
        self::checkIdentity($scopeIdentity, 'Scope');
        if (($tls === null) !== ($tlsPolicyIdentity === null)) {
            throw new InvalidArgumentException('TLS and its policy identity must be supplied together.');
        }
        if (($proxy === null) !== ($proxyPolicyIdentity === null)) {
            throw new InvalidArgumentException('Proxy endpoint and authorization policy identity must be supplied together.');
        }
        if ($tlsPolicyIdentity !== null) {
            self::checkIdentity($tlsPolicyIdentity, 'TLS');
        }
        if ($proxyPolicyIdentity !== null) {
            self::checkIdentity($proxyPolicyIdentity, 'Proxy');
        }
        if ($negotiatedAlpn !== null
            && ($tls === null || !in_array($negotiatedAlpn, $tls->alpnProtocols, true))) {
            throw new InvalidArgumentException('Negotiated ALPN must be offered by the TLS policy.');
        }

        $this->identity = hash('sha256', serialize([
            $endpoint->scheme,
            strtolower($endpoint->host ?? ''),
            $endpoint->port,
            $endpoint->path,
            $scopeIdentity,
            $tls === null ? null : strtolower($tls->peerName),
            $tls?->caFile,
            $tls?->caPath,
            $tls?->clientCertificate,
            $tls?->clientPrivateKey,
            $tls?->alpnProtocols,
            $tlsPolicyIdentity,
            $negotiatedAlpn,
            $proxy?->scheme,
            strtolower($proxy?->host ?? ''),
            $proxy?->port,
            $proxy?->path,
            $proxyPolicyIdentity,
        ]));
    }

    /** Compare two canonical pool isolation identities without disclosing policies. */
    public function equals(self $other): bool
    {
        return hash_equals($this->identity, $other->identity);
    }

    /** Return the stable digest for a pool or waiter-map key. */
    public function id(): string
    {
        return $this->identity;
    }

    private static function checkIdentity(string $identity, string $type): void
    {
        if ($identity === '' || strlen($identity) > 256
            || str_contains($identity, "\0")
            || preg_match('//u', $identity) !== 1) {
            throw new InvalidArgumentException($type . ' pool identity must be a nonempty, valid opaque identifier.');
        }
    }
}
