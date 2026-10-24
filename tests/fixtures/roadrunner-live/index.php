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
    $previousTempPath = null;
    Runtime::create(new RuntimeOptions(driver: RuntimeDriver::ROADRUNNER))->serve(
        static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$sequence, &$previousTempPath): void {
            ++$sequence;
            $priorClean = $previousTempPath === null || !file_exists($previousTempPath);
            $path = tempnam(sys_get_temp_dir(), 'runwire-rr-scope-');
            if (!is_string($path)) {
                throw new RuntimeException('RoadRunner fixture could not allocate a temporary file.');
            }
            $previousTempPath = $path;
            $file = null;
            $pair = null;
            $fileRoundtrip = false;
            $socketRoundtrip = false;

            try {
                $file = fopen($path, 'c+b');
                if (!is_resource($file)) {
                    throw new RuntimeException('RoadRunner fixture could not open its request file.');
                }
                $fileRoundtrip = fwrite($file, 'rr-file') === 7
                    && fflush($file)
                    && fseek($file, 0) === 0
                    && fread($file, 7) === 'rr-file';
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                if (!is_array($pair) || count($pair) !== 2) {
                    throw new RuntimeException('RoadRunner fixture Unix socket-pair is unavailable.');
                }
                $socketRoundtrip = fwrite($pair[0], 'peer') === 4
                    && fread($pair[1], 4) === 'peer';
            } finally {
                if (is_resource($file)) {
                    fclose($file);
                }
                if (is_array($pair)) {
                    foreach ($pair as $stream) {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                }
                if (is_file($path)) {
                    unlink($path);
                }
            }
            clearstatcache(true, $path);
            $fileClean = !is_resource($file) && !is_file($path);
            $socketClean = is_array($pair)
                && !is_resource($pair[0])
                && !is_resource($pair[1]);

            $data = json_encode([
                'method' => $request->method,
                'target' => $request->target,
                'pid' => getmypid(),
                'sequence' => $sequence,
                'prior_file_clean' => $priorClean,
                'file_roundtrip' => $fileRoundtrip,
                'file_closed' => $fileClean,
                'socket_roundtrip' => $socketRoundtrip,
                'socket_closed' => $socketClean,
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
