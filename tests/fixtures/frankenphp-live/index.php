<?php

declare(strict_types=1);

use Infocyph\Runwire\FrankenPhpOptions;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\Enum\FrankenPhpMode;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;

require '/runwire/vendor/autoload.php';

// Intentionally one real FrankenPHP worker: a fresh PHP request process would
// reset this counter to one, making lost persistent-worker ownership visible.
$handled = 0;
Runtime::create(new RuntimeOptions(
    driver: RuntimeDriver::FRANKENPHP,
    frankenPhp: new FrankenPhpOptions(mode: FrankenPhpMode::WORKER),
))->serve(
    static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$handled): void {
        ++$handled;
        $payload = json_encode([
            'method' => $request->method,
            'target' => $request->target,
            'pid' => getmypid(),
            'sequence' => $handled,
        ], JSON_THROW_ON_ERROR);
        $writer->start(200, Headers::fromArray([
            'content-type' => 'application/json',
            'content-length' => (string) strlen($payload),
        ]));
        $writer->end($payload);
    },
);
