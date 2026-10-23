<?php

declare(strict_types=1);

/**
 * Private off-loop worker entry point. No Composer bootstrap, global hooks or
 * caller-provided PHP code is executed in the child.
 */
const MAX_MESSAGE_BYTES = 1_600_000;
const MAX_FILE_BYTES = 1_048_576;

while (($line = fgets(STDIN, MAX_MESSAGE_BYTES + 2)) !== false) {
    if (strlen($line) > MAX_MESSAGE_BYTES || !str_ends_with($line, "\n")) {
        exit(2);
    }

    try {
        $request = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($request) || !isset($request['id'], $request['op']) || !is_int($request['id'])) {
            throw new InvalidArgumentException('Invalid worker request.');
        }

        $arguments = $request['args'] ?? [];
        if (!is_array($arguments)) {
            throw new InvalidArgumentException('Invalid worker arguments.');
        }

        $value = match ($request['op']) {
            'dns' => (static function (array $args): array {
                $host = $args['host'] ?? null;
                if (!is_string($host) || strlen($host) > 253 || $host === '' || str_contains($host, "\0")) {
                    throw new InvalidArgumentException('Invalid DNS host.');
                }
                $records = dns_get_record($host, DNS_A | DNS_AAAA);
                if ($records === false) {
                    throw new RuntimeException('DNS resolution failed.');
                }
                $addresses = [];
                foreach ($records as $record) {
                    $address = $record['ip'] ?? $record['ipv6'] ?? null;
                    if (is_string($address)) {
                        $addresses[] = $address;
                    }
                }

                return array_values(array_unique($addresses));
            })($arguments),
            'read' => (static function (array $args): string {
                $path = $args['path'] ?? null;
                $length = $args['length'] ?? null;
                $offset = $args['offset'] ?? 0;
                if (!is_string($path) || $path === '' || str_contains($path, "\0")
                    || !is_int($length) || $length < 0 || $length > MAX_FILE_BYTES
                    || !is_int($offset) || $offset < 0) {
                    throw new InvalidArgumentException('Invalid bounded file read.');
                }
                $data = file_get_contents($path, false, null, $offset, $length);
                if ($data === false) {
                    throw new RuntimeException('File read failed.');
                }

                return base64_encode($data);
            })($arguments),
            'stat' => (static function (array $args): array {
                $path = $args['path'] ?? null;
                if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
                    throw new InvalidArgumentException('Invalid file path.');
                }
                clearstatcache(true, $path);
                $stat = stat($path);
                if ($stat === false) {
                    throw new RuntimeException('File stat failed.');
                }

                return ['size' => $stat['size'], 'mtime' => $stat['mtime'], 'mode' => $stat['mode']];
            })($arguments),
            'sleep' => (static function (array $args): int {
                $milliseconds = $args['milliseconds'] ?? null;
                if (!is_int($milliseconds) || $milliseconds < 0 || $milliseconds > 2_000) {
                    throw new InvalidArgumentException('Worker fixture delay must be 0–2000 ms.');
                }
                usleep($milliseconds * 1_000);

                return $milliseconds;
            })($arguments),
            default => throw new InvalidArgumentException('Unsupported worker operation.'),
        };

        $response = ['id' => $request['id'], 'ok' => true, 'value' => $value];
    } catch (Throwable $error) {
        $response = [
            'id' => is_array($request ?? null) && is_int($request['id'] ?? null) ? $request['id'] : -1,
            'ok' => false,
            'error' => $error->getMessage(),
        ];
    }

    $encoded = json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    if (strlen($encoded) > MAX_MESSAGE_BYTES) {
        exit(3);
    }
    if (fwrite(STDOUT, $encoded . "\n") === false) {
        exit(4);
    }
    fflush(STDOUT);
}
