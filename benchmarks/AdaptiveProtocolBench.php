<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\AdaptiveProtocolPolicy;
use Infocyph\Runwire\Http\Enum\AdaptivePolicyMode;
use Infocyph\Runwire\Http\Http1\Internal\AdaptiveConnectionStrategy;
use Infocyph\Runwire\Http\Http2\Internal\AdaptiveResponseStrategy as Http2AdaptiveResponseStrategy;
use Infocyph\Runwire\Http\Http3\Internal\AdaptivePumpStrategy as Http3AdaptivePumpStrategy;
use Infocyph\Runwire\Http\Http3\Internal\AdaptiveResponseStrategy as Http3AdaptiveResponseStrategy;
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

    private Http3AdaptivePumpStrategy $http3PumpAuto;

    private Http3AdaptivePumpStrategy $http3PumpFixed;

    private Http3AdaptiveResponseStrategy $http3ResponseAuto;

    private Http3AdaptiveResponseStrategy $http3ResponseFixed;

    private AdaptiveLoadSample $low;

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
        $this->http3PumpAuto = new Http3AdaptivePumpStrategy(policy: $auto);
        $this->http3PumpFixed = new Http3AdaptivePumpStrategy(policy: $fixed);
        $this->http3ResponseAuto = new Http3AdaptiveResponseStrategy(policy: $auto);
        $this->http3ResponseFixed = new Http3AdaptiveResponseStrategy(policy: $fixed);
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

    public function benchHttp3PumpAutoHigh(): int
    {
        $this->http3PumpAuto->observe(100, 100, 100);

        return $this->http3PumpAuto->readLimit(256) + $this->http3PumpAuto->acceptLimit(64);
    }

    public function benchHttp3PumpAutoLow(): int
    {
        $this->http3PumpAuto->observe(0, 0, 100);

        return $this->http3PumpAuto->readLimit(256) + $this->http3PumpAuto->acceptLimit(64);
    }

    public function benchHttp3PumpFixed(): int
    {
        $this->http3PumpFixed->observe(100, 100, 100);

        return $this->http3PumpFixed->readLimit(256) + $this->http3PumpFixed->acceptLimit(64);
    }

    public function benchHttp3ResponseAutoHigh(): int
    {
        return $this->http3ResponseAuto->writeLimit($this->high, 128);
    }

    public function benchHttp3ResponseAutoLow(): int
    {
        return $this->http3ResponseAuto->writeLimit($this->low, 128);
    }

    public function benchHttp3ResponseFixed(): int
    {
        return $this->http3ResponseFixed->writeLimit($this->high, 128);
    }
}
