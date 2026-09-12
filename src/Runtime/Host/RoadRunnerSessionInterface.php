<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Infocyph\Runwire\Http\HttpRequest;

interface RoadRunnerSessionInterface
{
    public function waitRequest(int $maxRequestBodyBytes): ?HttpRequest;

    /** @param array<string, list<string>> $headers */
    public function respond(int $status, string $body, array $headers, bool $endOfStream): void;

    public function stop(): void;
}
