<?php

declare(strict_types=1);

use Infocyph\Runwire\Exception\ListenerException;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\TcpListener;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Internal\BoundServer;
use Infocyph\Runwire\Runtime\Internal\PortableNativeRuntime;
use Infocyph\Runwire\Runtime\RuntimeCapabilityResolver;
use Infocyph\Runwire\Runtime\RuntimeEnvironmentProbe;
use Infocyph\Runwire\Runtime\RuntimeSelection;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$assert = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

if (($argv[1] ?? null) === 'client') {
    $address = $argv[2] ?? '';
    if ($address === '') {
        $fail('Portable TLS client did not receive a listener address.');
    }

    $context = stream_context_create(['ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'alpn_protocols' => 'http/1.1',
    ]]);
    $errno = 0;
    $error = '';
    $client = stream_socket_client(
        'tcp://' . $address,
        $errno,
        $error,
        2.0,
        STREAM_CLIENT_CONNECT,
        $context,
    );
    if (!is_resource($client)) {
        $fail(sprintf('Portable TLS client connection failed: %s (%d).', $error, $errno));
    }
    stream_set_timeout($client, 2);
    if (stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
        fclose($client);
        $fail('Portable TLS client handshake failed.');
    }

    fwrite($client, "GET /portable-tls HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $response = stream_get_contents($client);
    fclose($client);
    if (!is_string($response) || !str_contains($response, 'HTTP/1.1 200') || !str_contains($response, 'portable-tls-ok')) {
        $fail('Portable TLS client received an invalid response.');
    }

    echo "portable-tls-client-ok\n";
    exit(0);
}

$environment = (new RuntimeEnvironmentProbe())->probe();
$assert(!function_exists('pcntl_fork'), 'PCNTL must be unavailable in portable optional-protocol acceptance.');
$capabilities = (new RuntimeCapabilityResolver())->resolve(RuntimeDriver::NATIVE, $environment);
$assert($capabilities->supportsHttp1, 'Portable native must retain HTTP/1.1 support.');
$assert($capabilities->supportsHttp2, 'Portable native must retain HTTP/2 support.');
$assert($capabilities->supportsHttp3 === $environment->supportsQuic, 'Portable HTTP/3 capability must follow QUIC availability.');
$assert($capabilities->supportsTlsAlpn === $environment->supportsOpenSsl, 'Portable TLS capability must follow usable OpenSSL support.');

$certificatePath = tempnam(sys_get_temp_dir(), 'runwire-portable-cert-');
if ($certificatePath === false) {
    $fail('Unable to allocate portable TLS certificate path.');
}
$keyPath = tempnam(sys_get_temp_dir(), 'runwire-portable-key-');
if ($keyPath === false) {
    @unlink($certificatePath);
    $fail('Unable to allocate portable TLS key path.');
}

if (($argv[1] ?? null) === 'unavailable') {
    file_put_contents($certificatePath, "placeholder\n");
    file_put_contents($keyPath, "placeholder\n");
    try {
        $assert(!function_exists('stream_socket_enable_crypto'), 'TLS-unavailable acceptance requires stream_socket_enable_crypto() to be disabled.');
        $assert(!$environment->supportsOpenSsl, 'Environment must not advertise usable native OpenSSL when TLS crypto is unavailable.');
        $assert(!$capabilities->supportsTlsAlpn, 'Portable native must not advertise TLS/ALPN when TLS crypto is unavailable.');

        try {
            TcpListener::bind(
                '127.0.0.1:0',
                tls: new TlsOptions($certificatePath, $keyPath),
            );
        } catch (ListenerException $error) {
            $assert(str_contains($error->getMessage(), 'TLS listeners require'), 'TLS-unavailable failure must be explicit.');
            echo "portable-tls-unavailable-ok\n";
            exit(0);
        }

        $fail('Configured TLS unexpectedly bound while TLS crypto was unavailable.');
    } finally {
        @unlink($certificatePath);
        @unlink($keyPath);
    }
}

$assert(extension_loaded('openssl'), 'Portable TLS success acceptance requires the OpenSSL extension.');
$assert(function_exists('stream_socket_enable_crypto'), 'Portable TLS success acceptance requires stream_socket_enable_crypto().');
$assert($environment->supportsOpenSsl, 'Environment must advertise usable OpenSSL for portable TLS success acceptance.');
$assert($capabilities->supportsTlsAlpn, 'Portable native must advertise TLS/ALPN when OpenSSL is usable.');

$key = openssl_pkey_new(['private_key_bits' => 2_048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($key === false) {
    $fail('Unable to create portable TLS private key.');
}
$csr = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
if ($csr === false) {
    $fail('Unable to create portable TLS certificate request.');
}
$certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
if ($certificate === false) {
    $fail('Unable to create portable TLS certificate.');
}
if (!openssl_x509_export($certificate, $certificatePem) || !openssl_pkey_export($key, $keyPem)) {
    $fail('Unable to export portable TLS credentials.');
}
file_put_contents($certificatePath, $certificatePem);
file_put_contents($keyPath, $keyPem);

$portable = null;
$server = Server::http(
    '127.0.0.1:0',
    static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$portable): void {
        if ($request->target !== '/portable-tls') {
            throw new RuntimeException('Unexpected portable TLS request target.');
        }
        $writer->end('portable-tls-ok');
        $portable?->stop();
    },
    'tls',
)->withTls(new TlsOptions(
    localCertificate: $certificatePath,
    privateKey: $keyPath,
    alpnProtocols: ['http/1.1'],
    handshakeTimeoutSeconds: 1.0,
));
$listener = TcpListener::bind($server->address, $server->listener, $server->connection, $server->tls);
$bound = ['tls' => new BoundServer($server, $listener)];
$selection = new RuntimeSelection(RuntimeDriver::NATIVE, $capabilities);
$portable = new PortableNativeRuntime(
    $bound,
    $selection,
    new RuntimeOptions(driver: RuntimeDriver::NATIVE),
);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open(
    [PHP_BINARY, __FILE__, 'client', $listener->address()],
    $descriptors,
    $pipes,
    options: ['bypass_shell' => true],
);
if (!is_resource($process)) {
    $listener->close();
    @unlink($certificatePath);
    @unlink($keyPath);
    $fail('Unable to start portable TLS acceptance client.');
}
fclose($pipes[0]);

try {
    $portable->run();
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $assert($exitCode === 0, sprintf('Portable TLS client failed (%d): %s', $exitCode, trim((string) $stderr)));
    $assert(str_contains((string) $stdout, 'portable-tls-client-ok'), 'Portable TLS client success marker missing.');
} finally {
    if (is_resource($pipes[1] ?? null)) {
        fclose($pipes[1]);
    }
    if (is_resource($pipes[2] ?? null)) {
        fclose($pipes[2]);
    }
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    $listener->close();
    @unlink($certificatePath);
    @unlink($keyPath);
}

echo "portable-tls-ok\n";
