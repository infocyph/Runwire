<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;

final class NativePhpResponseWriterFactory
{
    public function create(string $method, int $maxBodyBytes): CallbackResponseWriter
    {
        return new CallbackResponseWriter(
            static function (int $status, Headers $headers): void {
                http_response_code($status);
                foreach ($headers->fields() as $field) {
                    header($field->name . ': ' . $field->value, false);
                }
            },
            static function (string $chunk): void {
                echo $chunk;
            },
            static function (): void {},
            $maxBodyBytes,
            strtoupper($method) === 'HEAD',
        );
    }
}
