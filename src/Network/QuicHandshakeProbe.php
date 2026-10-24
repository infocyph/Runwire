<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Executor\ProcessExecutor;
use Infocyph\Runwire\RequestDeadline;
use InvalidArgumentException;
use Throwable;

/**
 * Genuine off-loop, verified native QUIC handshake probe.
 *
 * The ProcessExecutor worker owns the native QUIC object; no native connection
 * handle crosses PHP process boundaries. A success is only handshake evidence:
 * it is NOT a reusable QUIC session or an outbound HTTP/3 client transport.
 */
final readonly class QuicHandshakeProbe
{
    /** Use the same bounded, cancellation-aware process executor as DNS and filesystem. */
    public function __construct(private ProcessExecutor $executor) {}

    /**
     * Enqueue a verified QUIC client handshake outside the Runwire I/O loop.
     *
     * @param callable(mixed, ?Throwable): void $completion
     */
    public function connect(
        string $host,
        int $port,
        QuicClientOptions $options,
        callable $completion,
        ?CancellationToken $token = null,
        ?RequestDeadline $deadline = null,
    ): int {
        if ($host === '' || strlen($host) > 253 || preg_match('/[\x00-\x20\x7f]/', $host) === 1
            || $port < 1 || $port > 65_535) {
            throw new InvalidArgumentException('Invalid QUIC destination or UDP port.');
        }

        $native = $options->nativeOptions();
        $timeout = RequestDeadline::afterSeconds($options->connectTimeoutMilliseconds / 1_000, hrtime(true));
        if ($deadline === null || $deadline->monotonicNanoseconds === null
            || ($timeout->monotonicNanoseconds !== null && $timeout->monotonicNanoseconds < $deadline->monotonicNanoseconds)) {
            $deadline = $timeout;
        }

        return $this->executor->submit(
            'quic_probe',
            ['host' => $host, 'port' => $port, 'options' => $native],
            $completion,
            $token,
            $deadline,
        );
    }
}
