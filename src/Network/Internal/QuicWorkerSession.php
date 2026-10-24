<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * @internal Owns one native QUIC session and its streams inside one PHP worker.
 *
 * The host reactor sends only bounded command packets, never a native object.
 * This worker state must never be shared across ProcessExecutor workers.
 */
final class QuicWorkerSession
{
    private mixed $connection = null;

    private int $epoch = 0;

    private int $lastCommandId = 0;

    private int $maxOpenStreams = 0;

    private int $nextStreamId = 1;

    /** @var array<int, array{stream:object, bidirectional:bool}> */
    private array $streams = [];

    private function teardown(): void
    {
        $this->streams = [];
        if (is_object($this->connection) && method_exists($this->connection, 'close')) {
            $this->connection->close();
        }
        $this->connection = null;
        $this->maxOpenStreams = 0;
    }

    /**
     * Execute a validated command on the worker that owns this native session.
     *
     * @param array<array-key, mixed> $args
     * @return array<string, mixed>
     */
    public function execute(array $args): array
    {
        if (($args['action'] ?? null) === 'connect') {
            return $this->connect($args);
        }

        if ($this->connection === null || !is_int($args['epoch'] ?? null)
            || $args['epoch'] !== $this->epoch || !is_string($args['command'] ?? null)) {
            throw new RuntimeException('QUIC worker session is absent, closed or belongs to a different epoch.');
        }

        try {
            $command = QuicWorkerCommand::decode($args['command']);

            if ($command['id'] <= $this->lastCommandId) {
                throw new RuntimeException('Stale or replayed native QUIC worker command.');
            }
            $this->lastCommandId = $command['id'];

            return match ($command['op']) {
                'open_bidi' => $this->openStream(true),
                'open_uni' => $this->openStream(false),
                'write' => $this->writeStream($command),
                'read' => $this->readStream($command),
                'reset' => $this->resetStream($command),
                'close' => $this->closeSession(),
                default => throw new InvalidArgumentException('Unknown QUIC worker stream command.'),
            };
        } catch (Throwable $error) {
            // An invalid or failed native command cannot leave an unobserved
            // stream alive under a stale host-side session.
            $this->teardown();

            throw $error;
        }
    }

    /**
     * @param array<array-key, mixed> $protocols
     * @return list<string>
     */
    private function assertAlpn(array $protocols): array
    {
        if ($protocols === [] || count($protocols) > 16) {
            throw new InvalidArgumentException('QUIC worker requires 1–16 ALPN identifiers.');
        }

        $validated = [];
        foreach ($protocols as $protocol) {
            if (!is_string($protocol) || $protocol === '' || strlen($protocol) > 255
                || str_contains($protocol, ',') || str_contains($protocol, "\0")) {
                throw new InvalidArgumentException('Unsafe QUIC ALPN protocol identifier.');
            }

            $validated[] = $protocol;
        }

        return $validated;
    }

    private function assertEndpoint(string $host, int $port, int $limit): void
    {
        if ($host === '' || strlen($host) > 253 || preg_match('/[\x00-\x20\x7f]/', $host) === 1
            || $port < 1 || $port > 65_535 || $limit < 1 || $limit > 65_536) {
            throw new InvalidArgumentException('Invalid native QUIC endpoint or stream capacity.');
        }
    }

    /**
     * @param array<array-key, mixed> $options
     * @return list<string>
     */
    private function assertTlsOptions(array $options): array
    {
        if (($options['verify_peer'] ?? null) !== true
            || ($options['verify_peer_name'] ?? null) !== true
            || ($options['allow_self_signed'] ?? null) !== false
            || ($options['SNI_enabled'] ?? null) !== true
            || !is_string($options['peer_name'] ?? null)
            || !is_int($options['connect_timeout_ms'] ?? null)) {
            throw new InvalidArgumentException('QUIC native worker requires verified TLS 1.3 configuration.');
        }

        $timeout = $options['connect_timeout_ms'];
        if ($timeout < 1 || $timeout > 300_000) {
            throw new InvalidArgumentException('QUIC handshake timeout exceeds native worker budget.');
        }

        $protocols = $options['alpn'] ?? null;
        if (!is_array($protocols)) {
            throw new InvalidArgumentException('QUIC worker requires explicit ALPN negotiation.');
        }

        return $this->assertAlpn($protocols);
    }

    /** @return array{closed:bool} */
    private function closeSession(): array
    {
        $this->teardown();

        return ['closed' => true];
    }

    /**
     * @param array<array-key, mixed> $args
     * @return array{epoch:int, alpn:string, verified:bool}
     */
    private function connect(array $args): array
    {
        if ($this->connection !== null) {
            throw new RuntimeException('QUIC worker already owns a live session.');
        }

        $host = $args['host'] ?? null;
        $port = $args['port'] ?? null;
        $options = $args['options'] ?? null;
        $limit = $args['maxStreams'] ?? null;
        if (!is_string($host) || !is_int($port) || !is_array($options) || !is_int($limit)) {
            throw new InvalidArgumentException('Invalid native QUIC worker session configuration.');
        }
        $this->assertEndpoint($host, $port, $limit);
        $verifiedProtocols = $this->assertTlsOptions($options);

        $nativeClass = 'Quic' . '\Connection';
        if (!class_exists($nativeClass)) {
            throw new RuntimeException('Optional native ext-quic is unavailable for persistent sessions.');
        }

        // Native constructor may block: this function executes exclusively in
        // the bounded external worker process, NEVER the Runwire I/O loop.
        $connection = new $nativeClass($host, $port, $options);
        if (!is_object($connection)
            || !method_exists($connection, 'close')
            || !method_exists($connection, 'setBlocking')
            || !method_exists($connection, 'getNegotiatedAlpn')
            || !method_exists($connection, 'getVerifyResult')
            || !method_exists($connection, 'handleEvents')
            || !method_exists($connection, 'openStream')) {
            throw new RuntimeException('Native QUIC backend returned an invalid session handle.');
        }

        try {
            $alpn = $connection->getNegotiatedAlpn();
            if ($connection->getVerifyResult() !== 0
                || !is_string($alpn) || !in_array($alpn, $verifiedProtocols, true)) {
                throw new RuntimeException('QUIC session failed the required ALPN negotiation.');
            }
            $connection->setBlocking(false);
            ++$this->epoch;
            $this->connection = $connection;
            $this->streams = [];
            $this->lastCommandId = 0;
            $this->nextStreamId = 1;
            $this->maxOpenStreams = $limit;

            return ['epoch' => $this->epoch, 'alpn' => $alpn, 'verified' => true];
        } catch (Throwable $error) {
            $connection->close();

            throw $error;
        }
    }

    private function driveNativeEvents(): void
    {
        if (is_object($this->connection) && method_exists($this->connection, 'handleEvents')) {
            $this->connection->handleEvents();
        }
    }

    /**
     * Open a locally initiated QUIC stream, returning a worker-local handle.
     *
     * @return array{stream:int, bidirectional:bool}
     */
    private function openStream(bool $bidirectional): array
    {
        if (count($this->streams) >= $this->maxOpenStreams || $this->nextStreamId === PHP_INT_MAX) {
            throw new RuntimeException('QUIC native stream admission limit exceeded.');
        }

        $connection = $this->connection;
        if (!is_object($connection) || !method_exists($connection, 'openStream')) {
            throw new RuntimeException('Native QUIC session no longer owns a connection.');
        }
        $stream = $connection->openStream($bidirectional);
        if (!is_object($stream) || !method_exists($stream, 'write')
            || !method_exists($stream, 'read') || !method_exists($stream, 'reset')) {
            throw new RuntimeException('Native QUIC backend returned an invalid stream.');
        }
        $id = $this->nextStreamId++;
        $this->streams[$id] = ['stream' => $stream, 'bidirectional' => $bidirectional];

        return ['stream' => $id, 'bidirectional' => $bidirectional];
    }

    /**
     * @param array{id:int, op:string, stream:int, data:string, fin:bool} $command
     * @return array{data:string, eof:bool}
     */
    private function readStream(array $command): array
    {
        if (!($this->streams[$command['stream']]['bidirectional'] ?? false)) {
            throw new InvalidArgumentException('A client-initiated unidirectional stream cannot receive data.');
        }
        $stream = $this->stream($command['stream']);
        if (!method_exists($stream, 'read')) {
            throw new RuntimeException('Native QUIC stream cannot be read.');
        }
        $this->driveNativeEvents();
        $data = $stream->read(QuicWorkerCommand::MAX_DATA_BYTES);
        if ($data !== null && !is_string($data)) {
            throw new RuntimeException('Native QUIC stream returned invalid bytes.');
        }
        if ($data === null) {
            unset($this->streams[$command['stream']]);
        }

        return [
            'data' => is_string($data) ? base64_encode($data) : '',
            'eof' => $data === null,
        ];
    }

    /**
     * @param array{id:int, op:string, stream:int, data:string, fin:bool} $command
     * @return array{reset:bool}
     */
    private function resetStream(array $command): array
    {
        $stream = $this->stream($command['stream']);
        if (!method_exists($stream, 'reset')) {
            throw new RuntimeException('Native QUIC stream cannot be reset.');
        }
        $stream->reset();
        unset($this->streams[$command['stream']]);

        return ['reset' => true];
    }

    /** Return an established worker-local stream or fail closed. */
    private function stream(int $id): object
    {
        $entry = $this->streams[$id] ?? null;
        if ($entry === null) {
            throw new RuntimeException('Unknown or released native QUIC stream handle.');
        }

        return $entry['stream'];
    }

    /**
     * @param array{id:int, op:string, stream:int, data:string, fin:bool} $command
     * @return array{written:int}
     */
    private function writeStream(array $command): array
    {
        $stream = $this->stream($command['stream']);
        if (!method_exists($stream, 'write')) {
            throw new RuntimeException('Native QUIC stream cannot accept writes.');
        }
        $written = $stream->write($command['data'], $command['fin']);
        if (!is_int($written) || $written < 0 || $written > strlen($command['data'])
            || ($command['fin'] && $written !== strlen($command['data']))) {
            throw new RuntimeException('Native QUIC stream returned an invalid write length.');
        }
        $this->driveNativeEvents();

        return ['written' => $written];
    }
}
