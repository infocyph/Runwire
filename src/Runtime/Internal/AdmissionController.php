<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Internal;

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Metrics\RuntimeMetrics;
use Infocyph\Runwire\Runtime\AdmissionPolicy;

/**
 * Enforces per-worker request and stream admission limits.
 */
final class AdmissionController
{
    private int $activeRequests = 0;

    private int $activeStreams = 0;

    /**
     * Creates an admission controller backed by runtime metrics.
     */
    public function __construct(
        private readonly AdmissionPolicy $policy,
        private readonly RuntimeMetrics $metrics,
    ) {}

    /**
     * Attempts to admit a request for the supplied protocol version.
     */
    public function admit(ProtocolVersion $version): bool
    {
        if ($this->policy->maxActiveRequests > 0 && $this->activeRequests >= $this->policy->maxActiveRequests) {
            $this->metrics->recordRejectedRequest();

            return false;
        }

        if (self::isStream($version) && $this->policy->maxStreamsPerWorker > 0 && $this->activeStreams >= $this->policy->maxStreamsPerWorker) {
            $this->metrics->recordRejectedRequest();

            return false;
        }

        ++$this->activeRequests;
        if (self::isStream($version)) {
            ++$this->activeStreams;
        }

        return true;
    }

    /**
     * Releases admission counters after request completion.
     */
    public function release(ProtocolVersion $version): void
    {
        $this->activeRequests = max(0, $this->activeRequests - 1);
        if (self::isStream($version)) {
            $this->activeStreams = max(0, $this->activeStreams - 1);
        }
    }

    /**
     * Writes a protocol-appropriate 503 response for rejected work.
     */
    public function writeOverloadResponse(HttpRequest $request, ResponseWriterInterface $writer): void
    {
        if ($writer->isEnded()) {
            return;
        }

        if (!$writer->isStarted()) {
            $headers = [
                'content-length' => '0',
                'retry-after' => (string) $this->policy->retryAfterSeconds,
            ];
            if ($request->version === ProtocolVersion::HTTP_1_1) {
                $headers['connection'] = 'close';
            }
            $writer->start(503, Headers::fromArray($headers));
        }

        $writer->end();
    }

    private static function isStream(ProtocolVersion $version): bool
    {
        return $version === ProtocolVersion::HTTP_2 || $version === ProtocolVersion::HTTP_3;
    }
}
