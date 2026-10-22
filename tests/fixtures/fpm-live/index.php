<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$sequence = 0;
Runtime::create(new RuntimeOptions(driver: RuntimeDriver::FPM))->serve(
    static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$sequence): void {
        ++$sequence;
        $payload = json_encode([
            'method' => $request->method,
            'target' => $request->target,
            'pid' => getmypid(),
            'sequence' => $sequence,
            'sapi' => PHP_SAPI,
        ], JSON_THROW_ON_ERROR);
        $writer->start(200, Headers::fromArray([
            'content-type' => 'application/json',
            'content-length' => (string) strlen($payload),
        ]));
        $writer->end($payload);
    },
);
