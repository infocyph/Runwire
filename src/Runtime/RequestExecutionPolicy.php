<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\Metrics\DefaultRequestIdPolicy;
use Infocyph\Runwire\Metrics\GcPolicy;
use Infocyph\Runwire\Metrics\RequestIdPolicyInterface;
use Infocyph\Runwire\RequestDeadline;
use Infocyph\Runwire\RuntimeContext;
use InvalidArgumentException;

/**
 * Configures request deadlines, garbage collection, and request ID resolution.
 */
final readonly class RequestExecutionPolicy
{
    /**
     * Creates request execution policy settings.
     */
    public function __construct(
        public ?float $maxExecutionSeconds = null,
        public GcPolicy $gc = new GcPolicy(),
        public RequestIdPolicyInterface $requestIds = new DefaultRequestIdPolicy(),
    ) {
        if ($maxExecutionSeconds !== null && (!is_finite($maxExecutionSeconds) || $maxExecutionSeconds <= 0 || $maxExecutionSeconds > 86_400.0)) {
            throw new InvalidArgumentException('Maximum request execution time must be null or finite and between 0 and 86400 seconds.');
        }
    }

    /**
     * Resolves the request deadline from its monotonic start time.
     */
    public function deadline(int $startNanoseconds): RequestDeadline
    {
        return $this->maxExecutionSeconds === null
            ? RequestDeadline::unlimited()
            : RequestDeadline::afterSeconds($this->maxExecutionSeconds, $startNanoseconds);
    }

    /**
     * Resolves or generates a request identifier for the runtime.
     */
    public function requestId(RuntimeContext $runtime, ?string $candidate = null): string
    {
        return $this->requestIds->resolve($runtime, $candidate);
    }
}
