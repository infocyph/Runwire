<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Infocyph\Runwire\Http\HttpRequest;

/**
 * Defines request and response operations required by the RoadRunner driver.
 */
interface RoadRunnerSessionInterface
{
    /** @param array<string, list<string>> $headers */
    public function respond(int $status, string $body, array $headers, bool $endOfStream): void;

    /**
     * Stops the underlying RoadRunner worker session.
     */
    public function stop(): void;

    /**
     * Waits for the next normalized HTTP request, or null when the session ends.
     */
    public function waitRequest(int $maxRequestBodyBytes): ?HttpRequest;
}
