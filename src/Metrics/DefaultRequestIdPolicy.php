<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

use Infocyph\Runwire\RuntimeContext;
use RuntimeException;

/**
 * Resolves request IDs by optionally reusing valid supplied IDs or generating replacements.
 */
final readonly class DefaultRequestIdPolicy implements RequestIdPolicyInterface
{
    /**
     * Create the default request-ID policy.
     */
    public function __construct(
        private RequestIdGeneratorInterface $generator = new RandomRequestIdGenerator(),
        private bool $reuseSupplied = true,
    ) {}

    /**
     * Resolve a valid request ID for the runtime context.
     */
    public function resolve(RuntimeContext $runtime, ?string $candidate = null): string
    {
        if ($this->reuseSupplied && $candidate !== null && self::acceptable($candidate)) {
            return $candidate;
        }

        $generated = $this->generator->generate($runtime);
        if (!self::acceptable($generated)) {
            throw new RuntimeException('Request ID generator returned an invalid identifier.');
        }

        return $generated;
    }

    private static function acceptable(string $requestId): bool
    {
        return $requestId !== ''
            && strlen($requestId) <= 128
            && preg_match('/[\x00-\x1F\x7F]/', $requestId) !== 1;
    }
}
