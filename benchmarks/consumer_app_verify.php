<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeAdapter;

$consumer = $argv[1] ?? null;
if (!is_string($consumer) || !is_file($consumer . '/vendor/autoload.php')) {
    throw new InvalidArgumentException('Usage: php consumer_app_verify.php <infbyte-root>');
}

require $consumer . '/vendor/autoload.php';

$app = require $consumer . '/bootstrap/app.php';
$health = $app->handle(Request::fake(method: 'GET', uri: 'http://localhost/api/health'));
$payload = json_decode((string) $health->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($health->getStatusCode() !== 200 || $payload !== ['status' => 'ok']) {
    throw new RuntimeException('Infbyte health route failed under the candidate dependency graph.');
}

$status = null;
$body = '';
$ended = false;
$writer = new CallbackResponseWriter(
    static function (int $responseStatus, Headers $headers) use (&$status): void {
        unset($headers);
        $status = $responseStatus;
    },
    static function (string $chunk) use (&$body): void {
        $body .= $chunk;
    },
    static function () use (&$ended): void {
        $ended = true;
    },
    4096,
);
$request = new HttpRequest(
    method: 'GET',
    target: '/consumer-probe',
    version: ProtocolVersion::HTTP_1_1,
    headers: Headers::fromArray(['Host' => 'consumer.test']),
    body: new BufferedRequestBody(''),
);

$adapter = new RunwireRuntimeAdapter();
$context = $adapter->context($request, $writer);
$adapter->write(Response::plaintext('consumer-runwire-ok', 200), $context);

if ($status !== 200 || $body !== 'consumer-runwire-ok' || !$ended || !$writer->isEnded()) {
    throw new RuntimeException('Infbyte/Webrick Runwire bridge probe failed.');
}

$result = [
    'consumer' => 'infocyph/infbyte',
    'consumer_ref' => getenv('RUNWIRE_CONSUMER_REF') ?: 'main',
    'runwire_build' => getenv('RUNWIRE_CANDIDATE_BUILD') ?: 'unknown',
    'foundation_boot' => true,
    'health_route' => true,
    'webrick_runwire_adapter' => true,
    'response_status' => $status,
    'response_body' => $body,
    'correctness_passed' => true,
];

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
