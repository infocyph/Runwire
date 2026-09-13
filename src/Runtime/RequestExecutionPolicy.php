<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime;

use Infocyph\Runwire\RequestDeadline;
use InvalidArgumentException;

final readonly class RequestExecutionPolicy
{
    public function __construct(public ?float $maxExecutionSeconds = null)
    {
        if ($maxExecutionSeconds !== null && (!is_finite($maxExecutionSeconds) || $maxExecutionSeconds <= 0 || $maxExecutionSeconds > 86_400.0)) {
            throw new InvalidArgumentException('Maximum request execution time must be null or finite and between 0 and 86400 seconds.');
        }
    }

    public function deadline(int $startNanoseconds): RequestDeadline
    {
        return $this->maxExecutionSeconds === null
            ? RequestDeadline::unlimited()
            : RequestDeadline::afterSeconds($this->maxExecutionSeconds, $startNanoseconds);
    }
}
