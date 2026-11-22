<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Http\Client;

use InvalidArgumentException;

/** An exact outbound URI allowlist supplied by trusted application configuration. */
final readonly class ClientDestinationPolicy
{
    /** @var list<non-empty-string> */
    private array $allowedUris;

    /**
     * @param array<array-key,mixed> $allowedUris List of complete URIs including path and query.
     * @throws \InvalidArgumentException
     */
    public function __construct(array $allowedUris)
    {
        if ($allowedUris === [] || count($allowedUris) > 256 || !array_is_list($allowedUris)) {
            throw new InvalidArgumentException('Destination policy requires a list of 1 to 256 approved URIs.');
        }
        $verified = [];
        foreach ($allowedUris as $uri) {
            if (!is_string($uri) || $uri === '') {
                throw new InvalidArgumentException('Approved destination entries must be non-empty URI strings.');
            }
            $verified[] = $uri;
        }
        $this->allowedUris = $verified;
    }

    /**
     * Return the configured value only after matching the entire requested URI.
     * @throws \InvalidArgumentException
     */
    public function authorize(string $uri): string
    {
        foreach ($this->allowedUris as $approved) {
            if ($approved === $uri) {
                return $approved;
            }
        }

        throw new InvalidArgumentException('Outbound URI is not approved by the configured destination policy.');
    }
}
