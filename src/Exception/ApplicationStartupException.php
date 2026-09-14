<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Exception;

use Infocyph\Runwire\Runtime\Enum\ApplicationStartupPhase;
use RuntimeException;
use Throwable;

/**
 * Wraps a failure raised during a runtime application startup phase.
 */
final class ApplicationStartupException extends RuntimeException
{
    /**
     * Create a startup failure for the phase that failed.
     */
    public function __construct(
        public readonly ApplicationStartupPhase $phase,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf('Runtime application %s failed: %s', $phase->value, $previous->getMessage()),
            0,
            $previous,
        );
    }
}
