<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Runtime\Host;

use Infocyph\Runwire\Http\HeaderField;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use RuntimeException;

/**
 * Creates response writers backed by PHP's native response APIs and output stream.
 */
final class NativePhpResponseWriterFactory
{
    /**
     * Creates a bounded native PHP response writer for the request method.
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function create(string $method, int $maxBodyBytes): CallbackResponseWriter
    {
        $output = fopen('php://output', 'wb');
        if (!is_resource($output)) {
            throw new RuntimeException('Unable to open the host response output stream.');
        }

        return new CallbackResponseWriter(
            static function (int $status, Headers $headers): void {
                http_response_code($status);
                foreach ($headers->fields() as $field) {
                    header(self::validatedHeaderLine($field), false);
                }
            },
            static function (string $chunk) use ($output): void {
                $remaining = $chunk;
                while ($remaining !== '') {
                    $written = fwrite($output, $remaining);
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Unable to write the complete host response body.');
                    }
                    $remaining = substr($remaining, $written);
                }
            },
            static function () use ($output): void {
                fclose($output);
            },
            $maxBodyBytes,
            strtoupper($method) === 'HEAD',
        );
    }

    /**
     * Only immutable HeaderField instances that passed constructor validation
     * can reach this sink. Names cannot contain separators and values cannot
     * contain CR, LF, NUL or other forbidden control bytes.
     *
     */
    private static function validatedHeaderLine(HeaderField $field): string
    {
        return $field->name . ': ' . $field->value;
    }
}
