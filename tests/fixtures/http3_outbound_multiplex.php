<?php

declare(strict_types=1);

use Infocyph\Runwire\CancellationSource;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http3OutboundClient;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\QuicClientOptions;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($argc !== 3 || filter_var($argv[1], FILTER_VALIDATE_INT) === false) {
    throw new InvalidArgumentException('Expected independent QUIC port and trusted CA.');
}
$loop = new SelectLoop();
$policy = new ClientProtocolPolicy(
    preferred: ProtocolVersion::HTTP_3,
    allowedVersions: [ProtocolVersion::HTTP_3],
);
$options = new QuicClientOptions(
    'localhost',
    alpnProtocols: ['h3'],
    caFile: $argv[2],
    maxOpenStreams: 20,
    connectTimeoutMilliseconds: 4_000,
);
$requests = [];
$bodies = [];
$statuses = [];
$completions = [];
$request = static fn(int $id): ClientRequest => new ClientRequest(
    'GET',
    'https://localhost:' . $argv[1] . '/multi?i=' . $id,
    protocol: $policy,
);
$onHeaders = static function (int $id) use (&$statuses): callable {
    return static function (int $status) use ($id, &$statuses): void {
        $statuses[$id] = $status;
    };
};
$onBody = static function (int $id) use (&$bodies): callable {
    return static function (string $part) use ($id, &$bodies): void {
        $bodies[$id] = ($bodies[$id] ?? '') . $part;
    };
};
$onComplete = static function (int $id) use (&$completions): callable {
    return static function (?Throwable $error) use ($id, &$completions): void {
        if ($error !== null) {
            throw $error;
        }
        $completions[$id] = ($completions[$id] ?? 0) + 1;
    };
};
$client = Http3OutboundClient::connect(
    $loop,
    $request(1),
    $options,
    $onHeaders(1),
    $onBody(1),
    $onComplete(1),
);
for ($id = 2; $id <= 4; ++$id) {
    $client->enqueue($request($id), $onHeaders($id), $onBody($id), $onComplete($id));
}
$cancellation = new CancellationSource();
$cancelCallbacks = 0;
$client->enqueue(
    new ClientRequest(
        'GET',
        'https://localhost:' . $argv[1] . '/cancel',
        protocol: $policy,
        cancellation: $cancellation->token(),
    ),
    static function (): void {
        throw new RuntimeException('Cancelled sibling unexpectedly delivered headers.');
    },
    static function (): void {
        throw new RuntimeException('Cancelled sibling unexpectedly delivered DATA.');
    },
    static function (?Throwable $error) use (&$cancelCallbacks): void {
        if (!$error instanceof CancelledException) {
            throw new RuntimeException('Canceling one H3 sibling must report a cancellation exception.', 0, $error);
        }
        ++$cancelCallbacks;
    },
);
$cancelTimer = $loop->delay(0.30, static function () use ($cancellation): void {
    $cancellation->cancel(CancellationReason::HOST_CANCELLED);
});
$diagnosticTimer = $loop->delay(6.0, static function () use ($client, &$statuses, &$bodies, &$completions): void {
    $manager = (new ReflectionProperty($client, 'siblings'))->getValue($client);
    $active = $manager === null ? [] : (new ReflectionProperty($manager, 'exchanges'))->getValue($manager);
    $streams = [];
    foreach ($active as $id => $state) {
        $streams[$id] = [
            'stream' => $state->stream,
            'sent' => $state->sent,
            'reading' => $state->readActive,
            'peer_eof' => $state->peerEof,
            'response' => $state->response !== null,
        ];
    }
    $root = [
        'stream' => (new ReflectionProperty($client, 'responseStream'))->getValue($client),
        'settings' => (new ReflectionProperty($client, 'peerSettingsSeen'))->getValue($client),
        'completed' => (new ReflectionProperty($client, 'rootCompleted'))->getValue($client),
    ];
    fwrite(STDERR, 'C1 multiplex QA progress ' . json_encode([
        'headers' => $statuses,
        'completed' => $completions,
        'lengths' => array_map(strlen(...), $bodies),
        'root' => $root,
        'siblings' => $streams,
    ], JSON_THROW_ON_ERROR) . PHP_EOL);
});
$loop->run();
$loop->cancel($diagnosticTimer);
$loop->cancel($cancelTimer);
$cancellation->dispose();
ksort($statuses);
ksort($bodies);
ksort($completions);
$expected = array_fill(1, 4, 'runwire-outbound-h3-peer-ok');
if ($statuses !== [1 => 200, 2 => 200, 3 => 200, 4 => 200]
    || $bodies !== $expected
    || $completions !== [1 => 1, 2 => 1, 3 => 1, 4 => 1]
    || $cancelCallbacks !== 1
    || $loop->diagnostics()->readWatchers !== 0
    || $loop->diagnostics()->writeWatchers !== 0
    || $loop->diagnostics()->timersActive !== 0) {
    throw new RuntimeException('Independent concurrently gated HTTP/3 siblings failed or leaked loop resources.');
}
$client->close();
fwrite(STDOUT, json_encode([
    'verified' => true,
    'single_quic_session' => true,
    'concurrent_requests' => 4,
    'responses_completed' => count($completions),
    'exact_responses' => true,
    'native_sibling_cancel_exactly_once' => $cancelCallbacks === 1,
    'cleanup' => true,
], JSON_THROW_ON_ERROR) . PHP_EOL);
