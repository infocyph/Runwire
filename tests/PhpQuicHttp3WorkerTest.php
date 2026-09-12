<?php

declare(strict_types=1);

use Infocyph\Runwire\Http\Http3\FrameParser;
use Infocyph\Runwire\Http\Http3\FrameType;
use Infocyph\Runwire\Http\Http3\Http3Limits;
use Infocyph\Runwire\Http\Http3\Http3Options;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicConnection;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicEventMasks;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Poller;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicHttp3Worker;
use Infocyph\Runwire\Http\Http3\Quic\PhpQuicListener;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\TlsOptions;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\RuntimeEnvironmentProbe;
use Infocyph\Runwire\RuntimeDriver;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Server;

function workerQuicStream(int $id, bool $bidirectional): object
{
    return new class($id, $bidirectional) {
        public function __construct(
            private readonly int $id,
            private readonly bool $bidirectional,
        ) {}

        public function end(): void {}

        public function getId(): int
        {
            return $this->id;
        }

        public function getResetCode(): ?int
        {
            return null;
        }

        public function isBidirectional(): bool
        {
            return $this->bidirectional;
        }

        public function read(int $length): ?string
        {
            return $length > 0 ? '' : null;
        }

        public function reset(int $errorCode = 0): void {}

        public function write(string $data): int
        {
            return strlen($data);
        }
    };
}

/** @param list<?string> $negotiatedAlpns */
function workerQuicConnection(array $negotiatedAlpns = ['h3']): object
{
    return new class($negotiatedAlpns) {
        public bool $blocking = true;

        /** @var list<array{0: int, 1: string, 2: bool}> */
        public array $closed = [];

        /** @var list<object> */
        private array $localStreams;

        private ?string $lastNegotiatedAlpn = null;

        /** @param list<?string> $negotiatedAlpns */
        public function __construct(private array $negotiatedAlpns)
        {
            $this->localStreams = [
                workerQuicStream(3, false),
                workerQuicStream(7, false),
                workerQuicStream(11, false),
            ];
        }

        public function acceptStream(): ?object
        {
            return null;
        }

        public function close(int $errorCode = 0, string $reason = '', bool $rapid = false): void
        {
            $this->closed[] = [$errorCode, $reason, $rapid];
        }

        public function getNegotiatedAlpn(): ?string
        {
            if ($this->negotiatedAlpns !== []) {
                $this->lastNegotiatedAlpn = array_shift($this->negotiatedAlpns);
            }

            return $this->lastNegotiatedAlpn;
        }

        public function openStream(bool $bidirectional = true): object
        {
            $stream = array_shift($this->localStreams);
            if (!is_object($stream) || $stream->isBidirectional() !== $bidirectional) {
                throw new RuntimeException('Invalid worker test QUIC stream.');
            }

            return $stream;
        }

        public function setBlocking(bool $blocking): void
        {
            $this->blocking = $blocking;
        }
    };
}

function workerQuicListener(array $connections): object
{
    return new class($connections) {
        public bool $blocking = true;

        public bool $closed = false;

        /** @param list<object> $connections */
        public function __construct(private array $connections) {}

        public function accept(): ?object
        {
            return array_shift($this->connections);
        }

        public function close(): void
        {
            $this->closed = true;
        }

        public function setBlocking(bool $blocking): void
        {
            $this->blocking = $blocking;
        }
    };
}

/** @return array{0: string, 1: string} */
function workerHttp3CertificatePair(): array
{
    $certificatePath = tempnam(sys_get_temp_dir(), 'runwire-h3-cert-');
    $privateKeyPath = tempnam(sys_get_temp_dir(), 'runwire-h3-key-');
    if ($certificatePath === false || $privateKeyPath === false) {
        throw new RuntimeException('Unable to create temporary HTTP/3 certificate paths.');
    }

    try {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2_048,
        ]);
        if ($privateKey === false) {
            throw new RuntimeException('Unable to generate the HTTP/3 test private key.');
        }
        $request = openssl_csr_new(['commonName' => 'localhost'], $privateKey, ['digest_alg' => 'sha256']);
        if ($request === false) {
            throw new RuntimeException('Unable to generate the HTTP/3 test certificate request.');
        }
        $certificate = openssl_csr_sign($request, null, $privateKey, 1, ['digest_alg' => 'sha256']);
        if ($certificate === false) {
            throw new RuntimeException('Unable to self-sign the HTTP/3 test certificate.');
        }
        if (!openssl_x509_export_to_file($certificate, $certificatePath)) {
            throw new RuntimeException('Unable to export the HTTP/3 test certificate.');
        }
        if (!openssl_pkey_export_to_file($privateKey, $privateKeyPath)) {
            throw new RuntimeException('Unable to export the HTTP/3 test private key.');
        }

        return [$certificatePath, $privateKeyPath];
    } catch (Throwable $error) {
        if (file_exists($certificatePath)) {
            unlink($certificatePath);
        }
        if (file_exists($privateKeyPath)) {
            unlink($privateKeyPath);
        }

        throw $error;
    }
}

/** @return array{0: string, 1: int} */
function workerHttp3Endpoint(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!is_resource($probe)) {
        throw new RuntimeException(sprintf('Unable to reserve HTTP/3 test port: [%d] %s', $errno, $error));
    }

    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    if (!is_string($address)) {
        throw new RuntimeException('Unable to resolve the HTTP/3 test port.');
    }

    $separator = strrpos($address, ':');
    if ($separator === false) {
        throw new RuntimeException('HTTP/3 test endpoint did not contain a port.');
    }
    $port = (int) substr($address, $separator + 1);
    if ($port < 1) {
        throw new RuntimeException('HTTP/3 test endpoint resolved an invalid port.');
    }

    return [$address, $port];
}

function workerHttp3Connect(int $port): PhpQuicConnection
{
    $connectionClass = new ReflectionClass('Quic\\Connection');
    $lastError = null;
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        try {
            $connection = $connectionClass->newInstance('127.0.0.1', $port, [
                'alpn' => 'h3',
                'verify_peer' => false,
                'verify_peer_name' => false,
                'connect_timeout_ms' => 100,
                'idle_timeout_ms' => 2_000,
            ]);
            if (is_object($connection)) {
                return new PhpQuicConnection($connection);
            }
        } catch (Throwable $error) {
            $lastError = $error;
        }
        usleep(20_000);
    }

    $message = 'Unable to connect to the native HTTP/3 runtime.';
    if ($lastError instanceof Throwable) {
        $message .= ' Last QUIC error: ' . $lastError->getMessage();
    }

    throw new RuntimeException($message, 0, $lastError);
}

function workerHttp3RoundTrip(PhpQuicConnection $connection, string $authority): string
{
    $control = $connection->openStream(false);
    $encoder = $connection->openStream(false);
    $decoder = $connection->openStream(false);
    if ($control->write("\x00\x04\x00") !== 3 || $encoder->write("\x02") !== 1 || $decoder->write("\x03") !== 1) {
        throw new RuntimeException('Unable to initialize HTTP/3 client critical streams.');
    }

    $fields = "\x00\x00\xD1\xD7\x50" . chr(strlen($authority)) . $authority . "\xC1";
    $request = "\x01" . chr(strlen($fields)) . $fields;
    $stream = $connection->openStream(true);
    if ($stream->write($request, true) !== strlen($request)) {
        throw new RuntimeException('Unable to write the complete HTTP/3 request.');
    }

    $response = '';
    while (($chunk = $stream->read(65_535)) !== null) {
        if ($chunk === '') {
            usleep(1_000);

            continue;
        }
        $response .= $chunk;
        if (strlen($response) > 1_048_576) {
            throw new RuntimeException('HTTP/3 test response exceeded its safety bound.');
        }
    }

    $body = '';
    foreach ((new FrameParser())->push($response) as $frame) {
        if ($frame->type === FrameType::DATA->value) {
            $body .= $frame->payload;
        }
    }

    return $body;
}

/** @return array{0: int, 1: int} */
function workerHttp3Reap(int $runtimePid): array
{
    $status = 0;
    $deadline = microtime(true) + 3.0;
    do {
        $reaped = pcntl_waitpid($runtimePid, $status, WNOHANG);
        if ($reaped === $runtimePid) {
            return [$reaped, $status];
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);

    posix_kill($runtimePid, SIGKILL);
    $reaped = pcntl_waitpid($runtimePid, $status);

    return [$reaped, $status];
}

function workerHttp3StatusPath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'runwire-h3-status-');
    if ($path === false) {
        throw new RuntimeException('Unable to create the HTTP/3 worker status path.');
    }

    return $path;
}

function workerHttp3WriteStatus(string $path, string $status): void
{
    if (file_put_contents($path, $status, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the HTTP/3 worker status.');
    }
}

function workerHttp3AwaitStatus(string $path): void
{
    $deadline = microtime(true) + 2.0;
    do {
        $status = file_get_contents($path);
        if (is_string($status) && $status !== '') {
            if ($status === 'ready') {
                return;
            }

            throw new RuntimeException('Direct HTTP/3 worker failed: ' . $status);
        }
        usleep(10_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Direct HTTP/3 worker did not report readiness.');
}

function workerHttp3RunDirect(int $port, string $certificatePath, string $privateKeyPath, string $statusPath): never
{
    try {
        $options = new Http3Options();
        $tls = new TlsOptions($certificatePath, $privateKeyPath);
        $listener = PhpQuicListener::bind('127.0.0.1', $port, $options->listenerOptions($tls));
        $served = false;
        $worker = new PhpQuicHttp3Worker(
            $listener,
            static function (HttpRequest $request, ResponseWriterInterface $writer) use (&$served): void {
                $request->body->onEnd(static function () use (&$served, $writer): void {
                    $served = true;
                    $writer->end('runwire-http3-direct-ok');
                });
            },
            $options->limits,
            64,
        );
        workerHttp3WriteStatus($statusPath, 'ready');

        $deadline = microtime(true) + 3.0;
        do {
            $worker->tick($options->pollTimeoutSeconds);
            if ($served) {
                $deadline = min($deadline, microtime(true) + 0.25);
            }
        } while (microtime(true) < $deadline);

        $worker->stopAccepting();
        exit($served ? 0 : 71);
    } catch (Throwable $error) {
        workerHttp3WriteStatus($statusPath, $error::class . ': ' . $error->getMessage());
        exit(70);
    }
}

it('polls one worker-wide QUIC set, defers incomplete handshakes, and removes listener pressure at capacity', function (): void {
    $events = new PhpQuicEventMasks(1, 2, 4, 8, 16);
    $connectionRaw = workerQuicConnection([null, 'h3']);
    $listenerRaw = workerQuicListener([$connectionRaw]);
    $calls = [];
    $poller = new PhpQuicHttp3Poller(
        $events,
        static function (array $items, ?float $timeout) use (&$calls, $listenerRaw, $connectionRaw, $events): array {
            $calls[] = [$items, $timeout];
            if (count($calls) === 1) {
                return [spl_object_id($listenerRaw) => $events->acceptConnection];
            }
            if (count($calls) === 3) {
                return [spl_object_id($connectionRaw) => $events->error];
            }

            return [];
        },
    );
    $worker = new PhpQuicHttp3Worker(
        new PhpQuicListener($listenerRaw),
        static function (): void {},
        new Http3Limits(),
        1,
        $poller,
    );

    $worker->tick(0.0);
    expect($worker->connectionCount())->toBe(1)
        ->and($calls)->toHaveCount(1);

    $worker->tick(0.0);
    $secondItems = $calls[1][0];
    expect($calls)->toHaveCount(2)
        ->and($secondItems)->toHaveCount(1)
        ->and($secondItems)->not->toHaveKey(spl_object_id($listenerRaw))
        ->and($secondItems[spl_object_id($connectionRaw)][1] & $events->acceptStream)->not->toBe(0)
        ->and($secondItems[spl_object_id($connectionRaw)][1] & $events->error)->not->toBe(0);

    $worker->tick(0.0);
    $thirdItems = $calls[2][0];
    expect($calls)->toHaveCount(3)
        ->and(count($thirdItems))->toBeGreaterThan(1)
        ->and($thirdItems)->not->toHaveKey(spl_object_id($listenerRaw))
        ->and($worker->connectionCount())->toBe(0)
        ->and($worker->drainComplete())->toBeTrue();

    $worker->stopAccepting();
    expect($worker->accepting())->toBeFalse()
        ->and($listenerRaw->closed)->toBeTrue();
});

it('serves an interoperable HTTP/3 request through a direct native QUIC worker', function (): void {
    $environment = (new RuntimeEnvironmentProbe())->probe();
    if (!$environment->supportsQuic) {
        expect($environment->supportsQuic)->toBeFalse();

        return;
    }

    [$certificatePath, $privateKeyPath] = workerHttp3CertificatePair();
    [, $port] = workerHttp3Endpoint();
    $statusPath = workerHttp3StatusPath();
    $workerPid = pcntl_fork();
    expect($workerPid)->toBeGreaterThanOrEqual(0);
    if ($workerPid === 0) {
        workerHttp3RunDirect($port, $certificatePath, $privateKeyPath, $statusPath);
    }

    $client = null;
    $body = null;
    try {
        workerHttp3AwaitStatus($statusPath);
        $client = workerHttp3Connect($port);
        $body = workerHttp3RoundTrip($client, 'localhost');
    } finally {
        if ($client instanceof PhpQuicConnection) {
            $client->close(0, 'test complete', true);
        }
        [$reaped, $status] = workerHttp3Reap($workerPid);
        foreach ([$certificatePath, $privateKeyPath, $statusPath] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    expect($body)->toBe('runwire-http3-direct-ok')
        ->and($reaped)->toBe($workerPid)
        ->and(pcntl_wifexited($status))->toBeTrue()
        ->and(pcntl_wexitstatus($status))->toBe(0);
});

it('serves an interoperable HTTP/3 request through the full native runtime', function (): void {
    $environment = (new RuntimeEnvironmentProbe())->probe();
    if (!$environment->supportsQuic) {
        expect($environment->supportsQuic)->toBeFalse();

        return;
    }

    [$certificatePath, $privateKeyPath] = workerHttp3CertificatePair();
    [$address, $port] = workerHttp3Endpoint();
    $runtimePid = pcntl_fork();
    expect($runtimePid)->toBeGreaterThanOrEqual(0);
    if ($runtimePid === 0) {
        $server = Server::httpFactory($address, static function (): callable {
            return static function (HttpRequest $request, ResponseWriterInterface $writer): void {
                $request->body->onEnd(static fn () => $writer->end('runwire-http3-ok'));
            };
        })
            ->withTls(new TlsOptions($certificatePath, $privateKeyPath))
            ->withHttp3()
            ->withWorkers(1);

        Runtime::create(new RuntimeOptions(RuntimeDriver::NATIVE))->listen($server)->run();
        exit(0);
    }

    $client = null;
    $body = null;
    try {
        $client = workerHttp3Connect($port);
        $body = workerHttp3RoundTrip($client, 'localhost');
    } finally {
        if ($client instanceof PhpQuicConnection) {
            $client->close(0, 'test complete', true);
        }
        posix_kill($runtimePid, SIGTERM);
        [$reaped, $status] = workerHttp3Reap($runtimePid);
        if (file_exists($certificatePath)) {
            unlink($certificatePath);
        }
        if (file_exists($privateKeyPath)) {
            unlink($privateKeyPath);
        }
    }

    expect($body)->toBe('runwire-http3-ok')
        ->and($reaped)->toBe($runtimePid)
        ->and(pcntl_wifexited($status))->toBeTrue()
        ->and(pcntl_wexitstatus($status))->toBe(0);
});
