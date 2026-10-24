<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use InvalidArgumentException;

/** Explicit TLS/ALPN and HTTP version policy: no hidden downgrade or plaintext fallback. */
final readonly class ClientProtocolPolicy
{
    /**
     * @param list<ProtocolVersion> $allowedVersions
     */
    public function __construct(
        public ProtocolVersion $preferred = ProtocolVersion::HTTP_2,
        public array $allowedVersions = [ProtocolVersion::HTTP_2, ProtocolVersion::HTTP_1_1],
        public bool $allowFallback = false,
        public bool $allowPlaintext = false,
    ) {
        if ($allowedVersions === [] || count($allowedVersions) > 3) {
            throw new InvalidArgumentException('Allow one to three HTTP protocol versions.');
        }
        foreach ($allowedVersions as $version) {
            if (!$version instanceof ProtocolVersion) {
                throw new InvalidArgumentException('HTTP versions must use ProtocolVersion enum values.');
            }
        }
        if (count(array_unique(array_map(static fn(ProtocolVersion $version): string => $version->value, $allowedVersions))) !== count($allowedVersions)
            || !in_array($preferred, $allowedVersions, true)) {
            throw new InvalidArgumentException('HTTP versions must be distinct and include the preferred version.');
        }
    }

    /** Resolve a peer-negotiated version and reject every unapproved fallback. */
    public function negotiate(?string $alpn, bool $encrypted, bool $quic = false): ProtocolVersion
    {
        if (!$encrypted) {
            if ($quic || !$this->allowPlaintext || $alpn !== null || $this->preferred !== ProtocolVersion::HTTP_1_1) {
                throw new RuntimeUnavailableException('Plaintext HTTP is disabled by the requested client protocol policy.');
            }

            return ProtocolVersion::HTTP_1_1;
        }

        $negotiated = match ($alpn) {
            'h3' => ProtocolVersion::HTTP_3,
            'h2' => ProtocolVersion::HTTP_2,
            'http/1.1', null => ProtocolVersion::HTTP_1_1,
            default => throw new RuntimeUnavailableException('Peer returned an unsupported HTTP ALPN identifier.'),
        };
        if (($quic && $negotiated !== ProtocolVersion::HTTP_3)
            || (!$quic && $negotiated === ProtocolVersion::HTTP_3)
            || !in_array($negotiated, $this->allowedVersions, true)
            || (!$this->allowFallback && $negotiated !== $this->preferred)) {
            throw new RuntimeUnavailableException('HTTP version or transport downgrade violates the client protocol policy.');
        }

        return $negotiated;
    }
}
