<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Exception;

use RuntimeException;
use Throwable;

/**
 * Aggregates a request failure with failures raised during request reset.
 */
final class RequestLifecycleException extends RuntimeException
{
    /**
     * @param list<Throwable> $resetFailures
     */
    public function __construct(
        public readonly ?Throwable $requestFailure,
        public readonly array $resetFailures,
    ) {
        $message = $requestFailure === null
            ? sprintf('Request reset failed in %d resetter(s).', count($this->resetFailures))
            : sprintf(
                'Request failed and reset also failed in %d resetter(s): %s',
                count($this->resetFailures),
                $requestFailure->getMessage(),
            );

        parent::__construct($message, 0, $requestFailure ?? ($this->resetFailures[0] ?? null));
    }
}
