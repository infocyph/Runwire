<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Client\ClientBodySourceInterface;
use Infocyph\Runwire\Http\Client\ClientProtocolPolicy;
use Infocyph\Runwire\Http\Client\ClientRequest;
use Infocyph\Runwire\Http\Client\Http3OutboundClient;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Network\QuicClientOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($argc !== 3 || filter_var($argv[1], FILTER_VALIDATE_INT) === false) {
    throw new InvalidArgumentException('Expected independent QUIC port and trusted CA.');
}

$body = new class implements ClientBodySourceInterface {
    private string $remaining = '';

    public function __construct()
    {
        $this->remaining = str_repeat('upload-data-', 2730) . 'end';
    }

    public function eof(): bool
    {
        return $this->remaining === '';
    }

    public function read(int $maxBytes = PHP_INT_MAX): string
    {
        $chunk = substr($this->remaining, 0, min($maxBytes, 1024));
        $this->remaining = substr($this->remaining, strlen($chunk));

        return $chunk;
    }

    public function trailers(): ?Headers
    {
        return Headers::fromArray(['x-upload-finished' => 'yes']);
    }
};
$loop = new SelectLoop();
$response = '';
$statuses = 0;
$completed = 0;
$failure = null;
$client = Http3OutboundClient::connect(
    $loop,
    new ClientRequest(
        'POST',
        'https://localhost:' . $argv[1] . '/upload',
        body: $body,
        protocol: new ClientProtocolPolicy(
            preferred: ProtocolVersion::HTTP_3,
            allowedVersions: [ProtocolVersion::HTTP_3],
        ),
    ),
    new QuicClientOptions('localhost', alpnProtocols: ['h3'], caFile: $argv[2],
        maxOpenStreams: 16, connectTimeoutMilliseconds: 4_000),
    static function (int $status, Headers $headers) use (&$statuses): void {
        if ($status !== 200 || $headers->first('content-type') !== 'text/plain') {
            throw new RuntimeException('Independent HTTP/3 upload response metadata differs.');
        }
        ++$statuses;
    },
    static function (string $bytes) use (&$response): void {
        $response .= $bytes;
    },
    static function (?Throwable $error) use (&$failure, &$completed): void {
        $failure = $error;
        ++$completed;
    },
);
$loop->run();
if ($failure !== null) {
    throw $failure;
}
if ($completed !== 1 || $statuses !== 1 || !$body->eof()
    || $response !== 'runwire-outbound-h3-peer-ok'
    || $loop->diagnostics()->timersActive !== 0
    || $loop->diagnostics()->readWatchers !== 0
    || $loop->diagnostics()->writeWatchers !== 0) {
    throw new RuntimeException('Outbound H3 upload or native worker cleanup failed.');
}
$client->close();
fwrite(STDOUT, json_encode([
    'verified' => true,
    'independent_upload' => true,
    'trailing_headers' => true,
    'exact_response' => true,
    'cleanup' => true,
], JSON_THROW_ON_ERROR) . PHP_EOL);
