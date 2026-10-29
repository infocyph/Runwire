<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http3OutboundClient;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\QuicClientOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($argc !== 3 || filter_var($argv[1], FILTER_VALIDATE_INT) === false) {
    throw new InvalidArgumentException('Expected independent QUIC port and trusted CA.');
}

$loop = new SelectLoop();
$headersReceived = 0;
$body = '';
$finishCalls = 0;
$error = null;
$request = new ClientRequest(
    'GET',
    'https://localhost:' . $argv[1] . '/interop?q=1',
    protocol: new ClientProtocolPolicy(
        preferred: ProtocolVersion::HTTP_3,
        allowedVersions: [ProtocolVersion::HTTP_3],
    ),
);
$client = Http3OutboundClient::connect(
    $loop,
    $request,
    new QuicClientOptions('localhost', alpnProtocols: ['h3'], caFile: $argv[2],
        maxOpenStreams: 16, connectTimeoutMilliseconds: 4_000),
    static function (int $status, \Infocyph\Runwire\Http\Headers $headers) use (&$headersReceived): void {
        if ($status !== 200 || $headers->first('content-type') !== 'text/plain') {
            throw new RuntimeException('Independent HTTP/3 peer response headers are incorrect.');
        }
        ++$headersReceived;
    },
    static function (string $data) use (&$body): void {
        $body .= $data;
    },
    static function (?Throwable $failure) use (&$error, &$finishCalls): void {
        ++$finishCalls;
        $error = $failure;
    },
);
$loop->run();
if ($error !== null) {
    throw $error;
}
if ($headersReceived !== 1 || $finishCalls !== 1 || $body !== 'runwire-outbound-h3-peer-ok'
    || $loop->diagnostics()->readWatchers !== 0
    || $loop->diagnostics()->writeWatchers !== 0
    || $loop->diagnostics()->timersActive !== 0) {
    throw new RuntimeException('Independent outbound H3 exchange lost parity or leaked event-loop resources.');
}
$client->close();
fwrite(STDOUT, json_encode([
    'verified' => true,
    'http3_outbound_independent_peer' => true,
    'tls_alpn' => 'h3',
    'response_status' => 200,
    'exact_body' => true,
    'finished_exactly_once' => true,
    'loop_resources_released' => true,
], JSON_THROW_ON_ERROR) . PHP_EOL);
