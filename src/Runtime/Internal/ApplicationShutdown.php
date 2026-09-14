<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Exception\ApplicationShutdownException;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Throwable;

/**
 * Preserves primary runtime failures while completing application shutdown.
 */
final readonly class ApplicationShutdown
{
    /**
     * @param list<Throwable> $additionalFailures
     */
    public static function finish(
        RuntimeApplicationInterface $application,
        ?Throwable $primaryFailure = null,
        ?ShutdownReason $reason = null,
        array $additionalFailures = [],
    ): void {
        $failures = $additionalFailures;

        try {
            $application->shutdown($reason);
        } catch (ApplicationShutdownException $error) {
            $failures = [...$failures, ...$error->shutdownFailures];
            if ($error->primaryFailure !== null) {
                $failures[] = $error->primaryFailure;
            }
        } catch (Throwable $error) {
            $failures[] = $error;
        }

        self::resolve($primaryFailure, $failures);
    }

    /**
     * @param list<Throwable> $shutdownFailures
     */
    public static function resolve(?Throwable $primaryFailure, array $shutdownFailures): void
    {
        $flattened = [];
        foreach ($shutdownFailures as $failure) {
            if ($failure instanceof ApplicationShutdownException) {
                $flattened = [...$flattened, ...$failure->shutdownFailures];
                if ($failure->primaryFailure !== null) {
                    $flattened[] = $failure->primaryFailure;
                }

                continue;
            }
            $flattened[] = $failure;
        }

        if ($flattened !== []) {
            throw new ApplicationShutdownException($primaryFailure, $flattened);
        }
        if ($primaryFailure !== null) {
            throw $primaryFailure;
        }
    }
}
