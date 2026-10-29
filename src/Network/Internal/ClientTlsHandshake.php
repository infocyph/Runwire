<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Network\Internal;

use Closure;
use Infocyph\Runwire\Loop\LoopInterface;
use Infocyph\Runwire\Network\TlsClientOptions;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** @internal Drives verified client TLS over an already-connected raw TCP stream. */
final class ClientTlsHandshake
{
    private bool $finished = false;

    private ?TlsHandshakeReadiness $readiness = null;

    /** @var resource|null */
    private mixed $stream;

    /**
     * @param resource $stream
     * @param Closure(?string):void $onSuccess
     * @param Closure(Throwable):void $onFailure
     */
    private function __construct(
        private readonly LoopInterface $loop,
        mixed $stream,
        private readonly TlsClientOptions $policy,
        private readonly Closure $onSuccess,
        private readonly Closure $onFailure,
    ) {
        $this->stream = $stream;
    }

    /**
     * Start the handshake without blocking or taking socket-close ownership.
     *
     * @param resource $stream
     * @param callable(?string):void $onSuccess
     * @param callable(Throwable):void $onFailure
     */
    public static function start(
        LoopInterface $loop,
        mixed $stream,
        TlsClientOptions $policy,
        callable $onSuccess,
        callable $onFailure,
    ): self {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Client TLS requires a live connected TCP stream.');
        }
        $self = new self(
            $loop,
            $stream,
            $policy,
            Closure::fromCallable($onSuccess),
            Closure::fromCallable($onFailure),
        );
        $loop->defer(static function () use ($self): void {
            $self->begin();
        });

        return $self;
    }

    /** Cancel readiness watchers without transferring or closing socket ownership. */
    public function cancel(): void
    {
        $this->finish();
    }

    private function attempt(): void
    {
        $stream = $this->stream;
        if ($this->finished || !is_resource($stream)) {
            return;
        }
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            if ($level === E_WARNING) {
                $warning = $message;

                return true;
            }

            return false;
        });

        try {
            $result = stream_socket_enable_crypto($stream, true, $this->policy->method());
        } catch (Throwable $error) {
            $this->fail($error);

            return;
        } finally {
            restore_error_handler();
        }
        if ($result === 0) {
            return;
        }
        if ($result === false) {
            $this->fail(new RuntimeException($warning ?? 'TLS peer verification or handshake failed.'));

            return;
        }

        $meta = stream_get_meta_data($stream);
        $crypto = $meta['crypto'] ?? null;
        $alpn = is_array($crypto) ? ($crypto['alpn_protocol'] ?? null) : null;
        $this->finish();
        ($this->onSuccess)(is_string($alpn) ? $alpn : null);
    }

    private function begin(): void
    {
        $stream = $this->stream;
        if ($this->finished || !is_resource($stream)) {
            return;
        }

        try {
            $options = $this->policy->context();
            foreach ($options as $key => $value) {
                if (!stream_context_set_option($stream, 'ssl', $key, $value)) {
                    throw new RuntimeException('Unable to configure verified outbound TLS stream.');
                }
            }
            if (!stream_set_blocking($stream, false)) {
                throw new RuntimeException('TLS requires nonblocking stream mode.');
            }
            $this->readiness = new TlsHandshakeReadiness($this->loop);
            $this->readiness->start(
                $stream,
                $this->policy->handshakeTimeoutSeconds,
                fn() => $this->attempt(),
                fn() => $this->fail(new RuntimeException('Outbound TLS handshake timeout.')),
                true,
            );
            $this->attempt();
        } catch (Throwable $error) {
            $this->fail($error);
        }
    }

    private function fail(Throwable $error): void
    {
        if ($this->finished) {
            return;
        }

        $this->finish();
        ($this->onFailure)($error);
    }

    private function finish(): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;
        $this->readiness?->close();
        $this->readiness = null;
        $this->stream = null;
    }
}
