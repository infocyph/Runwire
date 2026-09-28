<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\AdaptiveProtocolPolicy;
use Infocyph\Runwire\Http\Enum\AdaptivePolicyMode;
use Infocyph\Runwire\Http\Http1\Internal\AdaptiveConnectionSampler;
use Infocyph\Runwire\Http\Http1\Internal\AdaptiveConnectionStrategy;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\Connection;

function adaptiveSamplerConnection(): array
{
    $streams = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($streams)->toBeArray()->toHaveCount(2);

    $loop = new SelectLoop();
    $connection = new Connection($loop, $streams[0]);

    return [$connection, $streams[1]];
}

it('bounds HTTP1 AUTO admission sampling independently of active population', function (): void {
    $sampler = new AdaptiveConnectionSampler(activeCapacity: 256, maxSamples: 2);
    $resources = [];
    $connections = [];

    foreach (range(1, 3) as $_) {
        [$connection, $peer] = adaptiveSamplerConnection();
        $connections[] = $connection;
        $resources[] = $peer;
        $sampler->add($connection);
    }

    $sample = $sampler->sample(10_000);

    expect($sampler->sampleSize())->toBe(2)
        ->and($sample->activeBasisPoints)->toBe(10_000)
        ->and($sample->backlogBasisPoints)->toBe(0);

    foreach ($connections as $connection) {
        $connection->abort();
    }
    foreach ($resources as $resource) {
        fclose($resource);
    }
});

it('bypasses HTTP1 load sampling for deterministic policy modes', function (): void {
    $fixed = new AdaptiveConnectionStrategy(
        policy: new AdaptiveProtocolPolicy(mode: AdaptivePolicyMode::FIXED),
    );
    $latency = new AdaptiveConnectionStrategy(
        policy: new AdaptiveProtocolPolicy(mode: AdaptivePolicyMode::LATENCY),
    );
    $throughput = new AdaptiveConnectionStrategy(
        policy: new AdaptiveProtocolPolicy(mode: AdaptivePolicyMode::THROUGHPUT),
    );

    expect($fixed->requiresLoadSample())->toBeFalse()
        ->and($latency->requiresLoadSample())->toBeFalse()
        ->and($throughput->requiresLoadSample())->toBeFalse()
        ->and($fixed->tcpNoDelay())->toBeTrue()
        ->and($latency->tcpNoDelay())->toBeTrue()
        ->and($throughput->tcpNoDelay())->toBeFalse()
        ->and((new AdaptiveConnectionStrategy())->requiresLoadSample())->toBeTrue();
});
