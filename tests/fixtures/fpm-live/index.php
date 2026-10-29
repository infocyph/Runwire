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
        $temp = tempnam(sys_get_temp_dir(), 'runwire-fpm-');
        if (!is_string($temp)) {
            throw new RuntimeException('FPM request could not create a temporary file.');
        }
        $file = null;
        $socketPair = null;
        $readAndWrite = false;
        $socketExchange = false;

        try {
            $file = fopen($temp, 'c+b');
            if (!is_resource($file)) {
                throw new RuntimeException('FPM request could not open its temporary file.');
            }
            $readAndWrite = fwrite($file, 'file') === 4
                && fflush($file)
                && fseek($file, 0) === 0
                && fread($file, 4) === 'file';

            $socketPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if (!is_array($socketPair) || count($socketPair) !== 2) {
                throw new RuntimeException('FPM request socket-pair capability is unavailable.');
            }
            $socketExchange = fwrite($socketPair[0], 'wire') === 4
                && fread($socketPair[1], 4) === 'wire';
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            if (is_array($socketPair)) {
                foreach ($socketPair as $socket) {
                    if (is_resource($socket)) {
                        fclose($socket);
                    }
                }
            }
            if (is_file($temp)) {
                unlink($temp);
            }
        }
        clearstatcache(true, $temp);
        $fileClosed = !is_resource($file) && !is_file($temp);
        $socketClosed = is_array($socketPair)
            && !is_resource($socketPair[0])
            && !is_resource($socketPair[1]);

        $payload = json_encode([
            'method' => $request->method,
            'target' => $request->target,
            'pid' => getmypid(),
            'sequence' => $sequence,
            'sapi' => PHP_SAPI,
            'file_roundtrip' => $readAndWrite,
            'file_closed' => $fileClosed,
            'socket_roundtrip' => $socketExchange,
            'socket_closed' => $socketClosed,
        ], JSON_THROW_ON_ERROR);
        $writer->start(200, Headers::fromArray([
            'content-type' => 'application/json',
            'content-length' => (string) strlen($payload),
        ]));
        $writer->end($payload);
    },
);
