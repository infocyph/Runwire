<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use InvalidArgumentException;

/**
 * Secure outbound QUIC intent and native-backend capability contract.
 *
 * ext-quic currently provides TLS 1.3 streams but not unreliable datagrams,
 * connection migration or application 0-RTT. Unsupported requirements fail
 * before a native connection is constructed, never silently degrade.
 */
final readonly class QuicClientOptions
{
    /**
     * @param list<string> $alpnProtocols
     */
    public function __construct(
        public string $peerName,
        public array $alpnProtocols = ['h3'],
        public ?string $caFile = null,
        public ?string $caPath = null,
        public int $connectTimeoutMilliseconds = 10_000,
        public int $maxOpenStreams = 64,
        public bool $allowEarlyData = false,
        public bool $allowMigration = false,
        public bool $requireDatagrams = false,
    ) {
        self::validateIdentity($peerName, $caFile, $caPath);
        self::validateAlpn($alpnProtocols);
        if ($connectTimeoutMilliseconds < 1 || $connectTimeoutMilliseconds > 300_000) {
            throw new InvalidArgumentException('QUIC connect timeout must be 1–300000 milliseconds.');
        }
        if ($maxOpenStreams < 1 || $maxOpenStreams > 65_536) {
            throw new InvalidArgumentException('QUIC open stream limit must be 1–65536.');
        }
    }

    /**
     * Reject application dispatch until the verified TLS 1.3 handshake has
     * completed; the native backend provides no replay-safe 0-RTT support.
     */
    public function assertApplicationDispatchAllowed(bool $handshakeConfirmed): void
    {
        if (!$handshakeConfirmed) {
            throw new RuntimeUnavailableException('Unverified QUIC 0-RTT application dispatch is forbidden.');
        }
    }

    /**
     * The native backend cannot negotiate RFC 9221 datagrams.
     * Application writers must not treat reliable streams as datagrams.
     */
    public function assertDatagramAvailable(): void
    {
        throw new RuntimeUnavailableException('Native QUIC RFC9221 datagrams are unavailable; cannot send or receive an unreliable datagram.');
    }

    /**
     * Refuse a synchronous handshake on the owner event-loop thread.
     * A worker/process backend must own the native connection lifecycle
     * before this interface may be used to expose an async dialer.
     */
    public function assertEventLoopSafeNativeClient(): void
    {
        throw new RuntimeUnavailableException(
            'ext-quic client construction blocks during TLS handshake; direct loop-thread dialing is prohibited.',
        );
    }

    /** Refuse unsupported native QUIC features instead of silently ignoring them. */
    public function assertNativeCapabilities(): void
    {
        if ($this->allowEarlyData) {
            throw new RuntimeUnavailableException('Native QUIC 0-RTT is unsupported and must not dispatch early application data.');
        }
        if ($this->allowMigration) {
            throw new RuntimeUnavailableException('Native QUIC connection migration is unsupported; rebinding is rejected.');
        }
        if ($this->requireDatagrams) {
            throw new RuntimeUnavailableException('Native QUIC RFC9221 unreliable datagrams are unsupported.');
        }
    }

    /**
     * The native QUIC backend has no post-handshake connection migration.
     * Reject address/port rebinding instead of pretending a QUIC connection
     * ID makes the new path routable.
     */
    public function assertStablePath(string $establishedPath, string $candidatePath): void
    {
        if ($establishedPath === '' || $candidatePath === '' || $establishedPath !== $candidatePath) {
            throw new RuntimeUnavailableException('Native QUIC migration/rebinding is unavailable: the established peer path must remain fixed.');
        }
    }

    /**
     * Advertise only capabilities offered by the current native backend.
     *
     * @return array<string, bool>
     */
    public function nativeCapabilities(): array
    {
        return [
            'tls13' => true,
            'bidirectional_streams' => true,
            'unidirectional_streams' => true,
            'datagrams' => false,
            'migration' => false,
            'early_data' => false,
            'nonblocking_client_handshake' => false,
        ];
    }

    /**
     * Return a verified TLS 1.3 client policy for the ext-quic backend.
     *
     * @return array<string, bool|int|string|list<string>>
     */
    public function nativeOptions(): array
    {
        $this->assertNativeCapabilities();
        $options = [
            'alpn' => $this->alpnProtocols,
            'peer_name' => $this->peerName,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'connect_timeout_ms' => $this->connectTimeoutMilliseconds,
            'verify_depth' => 8,
        ];
        if ($this->caFile !== null) {
            $options['cafile'] = $this->caFile;
        }
        if ($this->caPath !== null) {
            $options['capath'] = $this->caPath;
        }

        return $options;
    }

    /**
     * @param list<string> $alpnProtocols
     */
    private static function validateAlpn(array $alpnProtocols): void
    {
        if ($alpnProtocols === [] || count($alpnProtocols) > 16) {
            throw new InvalidArgumentException('QUIC ALPN requires 1–16 distinct protocol identifiers.');
        }
        foreach ($alpnProtocols as $protocol) {
            if ($protocol === '' || strlen($protocol) > 255 || str_contains($protocol, ',') || str_contains($protocol, "\0")) {
                throw new InvalidArgumentException('Invalid QUIC ALPN protocol identifier.');
            }
        }
        if (count(array_unique($alpnProtocols)) !== count($alpnProtocols)) {
            throw new InvalidArgumentException('QUIC ALPN values must be unique.');
        }
    }

    private static function validateIdentity(string $peerName, ?string $caFile, ?string $caPath): void
    {
        if ($peerName === '' || strlen($peerName) > 253 || str_contains($peerName, "\0") || preg_match('/\s/', $peerName)) {
            throw new InvalidArgumentException('QUIC peer identity must be a valid nonempty hostname.');
        }
        if ($caFile !== null && ($caFile === '' || !is_file($caFile))) {
            throw new InvalidArgumentException('QUIC CA file must exist.');
        }
        if ($caPath !== null && ($caPath === '' || !is_dir($caPath))) {
            throw new InvalidArgumentException('QUIC CA directory must exist.');
        }
    }
}
