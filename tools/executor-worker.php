<?php

declare(strict_types=1);

/**
 * Private off-loop worker entry point. No Composer bootstrap, global hooks or
 * caller-provided PHP code is executed in the child.
 */
const MAX_MESSAGE_BYTES = 1_600_000;
const MAX_FILE_BYTES = 1_048_576;

// This state belongs to this one long-lived subprocess and can never escape
// through the host's nonblocking IPC transport.
$quicSessionWorker = null;

while (($line = fgets(STDIN, MAX_MESSAGE_BYTES + 2)) !== false) {
    if (strlen($line) > MAX_MESSAGE_BYTES || !str_ends_with($line, "\n")) {
        throw new RuntimeException('Executor received an oversized or unterminated request.');
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
                set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);

                try {
                    $records = dns_get_record($host, DNS_A | DNS_AAAA);
                    $fallback = $records === false || $records === []
                        ? gethostbynamel($host)
                        : false;
                } finally {
                    restore_error_handler();
                }
                $addresses = [];
                if (is_array($records)) {
                    foreach ($records as $record) {
                        $address = $record['ip'] ?? $record['ipv6'] ?? null;
                        if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                            $addresses[] = $address;
                        }
                    }
                }
                if (is_array($fallback)) {
                    foreach ($fallback as $address) {
                        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                            $addresses[] = $address;
                        }
                    }
                }

                return array_values(array_unique($addresses));
            })($arguments),
            'quic_session' => (static function (array $args) use (&$quicSessionWorker): array {
                if ($quicSessionWorker === null) {
                    require_once dirname(__DIR__) . '/src/Network/Internal/QuicWorkerCommand.php';
                    require_once dirname(__DIR__) . '/src/Network/Internal/QuicWorkerSession.php';
                    $quicSessionWorker = new \Infocyph\Runwire\Network\Internal\QuicWorkerSession();
                }

                return $quicSessionWorker->execute($args);
            })($arguments),
            'quic_probe' => (static function (array $args): array {
                $host = $args['host'] ?? null;
                $port = $args['port'] ?? null;
                $options = $args['options'] ?? null;
                if (!is_string($host) || $host === '' || strlen($host) > 253
                    || preg_match('/[\x00-\x20\x7f]/', $host) === 1
                    || !is_int($port) || $port < 1 || $port > 65_535
                    || !is_array($options)
                    || ($options['verify_peer'] ?? null) !== true
                    || ($options['verify_peer_name'] ?? null) !== true
                    || ($options['allow_self_signed'] ?? null) !== false
                    || ($options['SNI_enabled'] ?? null) !== true
                    || !is_string($options['peer_name'] ?? null)
                    || !is_array($options['alpn'] ?? null)
                    || $options['alpn'] === []) {
                    throw new InvalidArgumentException('Invalid or unverified outbound QUIC probe.');
                }
                // External extension classes are resolved dynamically by the
                // worker because they are not a mandatory Composer dependency.
                $quicConnectionClass = 'Quic' . '\\Connection';

                if (!class_exists($quicConnectionClass)) {
                    throw new RuntimeException('Optional native ext-quic is unavailable for outbound QUIC.');
                }

                // This constructor performs its full TLS handshake *before*
                // setBlocking(false) is possible. It may only run here, in
                // the isolated, deadline-limited executor worker process.
                $connection = new $quicConnectionClass($host, $port, $options);
                if (!is_object($connection) || !method_exists($connection, 'close')
                    || !method_exists($connection, 'getNegotiatedAlpn')) {
                    throw new RuntimeException('Native QUIC backend returned an invalid connection handle.');
                }

                try {
                    $negotiatedAlpn = $connection->getNegotiatedAlpn();
                    if (!is_string($negotiatedAlpn) || !in_array($negotiatedAlpn, $options['alpn'], true)) {
                        throw new RuntimeException('Verified QUIC peer failed the required ALPN negotiation.');
                    }

                    return [
                        'verified' => true,
                        'backend' => 'ext-quic',
                        'alpn' => $negotiatedAlpn,
                    ];
                } finally {
                    $connection->close();
                }
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
        throw new RuntimeException('Executor response exceeds IPC limit.');
    }
    if (fwrite(STDOUT, $encoded . "\n") === false) {
        throw new RuntimeException('Executor output closed.');
    }
    fflush(STDOUT);
}
