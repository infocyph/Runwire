<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Benchmarks;

use Infocyph\Runwire\Http\AdaptiveProtocolPolicy;
use Infocyph\Runwire\Http\Enum\AdaptivePolicyMode;
use Infocyph\Runwire\Http\Http1\Internal\AdaptiveConnectionStrategy;
use Infocyph\Runwire\Http\Http2\Internal\AdaptiveResponseStrategy as Http2AdaptiveResponseStrategy;
use Infocyph\Runwire\Http\Internal\AdaptiveLoadSample;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[BeforeMethods('setUp')]
#[Iterations(5)]
#[Revs(10_000)]
#[Warmup(1)]
final class AdaptiveProtocolBench
{
    private AdaptiveLoadSample $high;

    private AdaptiveConnectionStrategy $http1Auto;

    private AdaptiveConnectionStrategy $http1Fixed;

    private Http2AdaptiveResponseStrategy $http2Auto;

    private Http2AdaptiveResponseStrategy $http2Fixed;

    private AdaptiveLoadSample $low;

    /**
     * Initialize fixtures before PHPBench's per-iteration setup refresh.
     * @throws \InvalidArgumentException
     */
    public function __construct()
    {
        $this->setUp();
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function setUp(): void
    {
        $auto = new AdaptiveProtocolPolicy(
            transitionSamples: 1,
            ewmaNumerator: 1,
            ewmaDenominator: 1,
        );
        $fixed = new AdaptiveProtocolPolicy(mode: AdaptivePolicyMode::FIXED);

        $this->high = new AdaptiveLoadSample(true, 10_000, 10_000);
        $this->http1Auto = new AdaptiveConnectionStrategy(policy: $auto);
        $this->http1Fixed = new AdaptiveConnectionStrategy(policy: $fixed);
        $this->http2Auto = new Http2AdaptiveResponseStrategy(policy: $auto);
        $this->http2Fixed = new Http2AdaptiveResponseStrategy(policy: $fixed);
        $this->low = new AdaptiveLoadSample(false, 0, 0);
    }

    public function benchHttp1AutoHigh(): bool
    {
        return $this->http1Auto->tcpNoDelay($this->high);
    }

    public function benchHttp1AutoLow(): bool
    {
        return $this->http1Auto->tcpNoDelay($this->low);
    }

    public function benchHttp1Fixed(): bool
    {
        return $this->http1Fixed->tcpNoDelay($this->high);
    }

    public function benchHttp2AutoHigh(): int
    {
        return $this->http2Auto->wireLimit($this->high);
    }

    public function benchHttp2AutoLow(): int
    {
        return $this->http2Auto->wireLimit($this->low);
    }

    public function benchHttp2Fixed(): int
    {
        return $this->http2Fixed->wireLimit($this->high);
    }
}
