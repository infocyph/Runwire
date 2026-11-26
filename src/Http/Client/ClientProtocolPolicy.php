<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use Infocyph\Runwire\Exception\RuntimeUnavailableException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use InvalidArgumentException;

/** Explicit TLS/ALPN, HTTP version and optional outbound destination policy. */
final readonly class ClientProtocolPolicy
{
    /**
     * @param list<ProtocolVersion> $allowedVersions
     * @param ClientDestinationPolicy|null $destinationPolicy Exact URI allowlist enforced at send time, including redirects.
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public ProtocolVersion $preferred = ProtocolVersion::HTTP_2,
        public array $allowedVersions = [ProtocolVersion::HTTP_2, ProtocolVersion::HTTP_1_1],
        public bool $allowFallback = false,
        public bool $allowPlaintext = false,
        public ?ClientDestinationPolicy $destinationPolicy = null,
    ) {
        if ($allowedVersions === [] || count($allowedVersions) > 2) {
            throw new InvalidArgumentException('Allow one or two HTTP protocol versions.');
        }
        if (count(array_unique(array_map(static fn(ProtocolVersion $version): string => $version->value, $allowedVersions))) !== count($allowedVersions)
            || !in_array($preferred, $allowedVersions, true)) {
            throw new InvalidArgumentException('HTTP versions must be distinct and include the preferred version.');
        }
    }

    /**
     * Resolve a peer-negotiated version and reject every unapproved fallback.
     * @throws \Infocyph\Runwire\Exception\RuntimeUnavailableException
     */
    public function negotiate(?string $alpn, bool $encrypted): ProtocolVersion
    {
        if (!$encrypted) {
            if (!$this->allowPlaintext || $alpn !== null || $this->preferred !== ProtocolVersion::HTTP_1_1) {
                throw new RuntimeUnavailableException('Plaintext HTTP is disabled by the requested client protocol policy.');
            }

            return ProtocolVersion::HTTP_1_1;
        }

        $negotiated = match ($alpn) {
            'h2' => ProtocolVersion::HTTP_2,
            'http/1.1', null => ProtocolVersion::HTTP_1_1,
            default => throw new RuntimeUnavailableException('Peer returned an unsupported HTTP ALPN identifier.'),
        };
        if (!in_array($negotiated, $this->allowedVersions, true)
            || (!$this->allowFallback && $negotiated !== $this->preferred)) {
            throw new RuntimeUnavailableException('HTTP version or transport downgrade violates the client protocol policy.');
        }

        return $negotiated;
    }
}
