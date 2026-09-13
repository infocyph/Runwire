<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Metrics;

use Infocyph\Runwire\RuntimeContext;
use RuntimeException;

final readonly class DefaultRequestIdPolicy implements RequestIdPolicyInterface
{
    public function __construct(
        private RequestIdGeneratorInterface $generator = new RandomRequestIdGenerator(),
        private bool $reuseSupplied = true,
    ) {}

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
