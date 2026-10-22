<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;

$diagnosticPath = sys_get_temp_dir() . '/runwire-road-runner-worker-error.log';
file_put_contents($diagnosticPath, sprintf(
    "BOOT pid=%d RR_MODE=%s RR_RELAY=%s%s",
    getmypid(),
    (string) getenv('RR_MODE'),
    (string) getenv('RR_RELAY'),
    PHP_EOL,
), FILE_APPEND);

try {
    require dirname(__DIR__, 3) . '/vendor/autoload.php';

    $sequence = 0;
    Runtime::create(new RuntimeOptions(driver: RuntimeDriver::ROADRUNNER))->serve(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$sequence): void {
            ++$sequence;
            $data = json_encode([
                'method' => $request->method,
                'target' => $request->target,
                'pid' => getmypid(),
                'sequence' => $sequence,
            ], JSON_THROW_ON_ERROR);
            $writer->start(200, Headers::fromArray([
                'content-type' => 'application/json',
                'content-length' => (string) strlen($data),
            ]));
            $writer->end($data);
        },
    );
} catch (Throwable $exception) {
    $message = sprintf('%s: %s%s', $exception::class, $exception->getMessage(), PHP_EOL);
    file_put_contents($diagnosticPath, $message, FILE_APPEND);
    fwrite(STDERR, $message);

    throw $exception;
}
