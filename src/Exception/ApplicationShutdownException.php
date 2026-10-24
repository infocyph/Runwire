<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Exception;

use RuntimeException;
use Throwable;

/**
 * Retains a primary runtime failure together with every shutdown failure.
 */
final class ApplicationShutdownException extends RuntimeException
{
    /**
     * @param list<Throwable> $shutdownFailures
     */
    public function __construct(
        public readonly ?Throwable $primaryFailure,
        public readonly array $shutdownFailures,
    ) {
        $message = $primaryFailure === null
            ? sprintf('Application shutdown failed in %d operation(s).', count($shutdownFailures))
            : sprintf(
                'Application failed and shutdown also failed in %d operation(s): %s',
                count($shutdownFailures),
                $primaryFailure->getMessage(),
            );

        parent::__construct($message, 0, $primaryFailure ?? ($shutdownFailures[0] ?? null));
    }
}
