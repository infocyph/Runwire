<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;

final readonly class RuntimeCapabilities
{
    public function __construct(
        public RuntimeDriver $driver,
        public bool $persistentProcess = false,
        public bool $persistentApplication = false,
        public bool $ownsListener = false,
        public bool $ownsEventLoop = false,
        public bool $ownsWorkerPool = false,
        public bool $supportsFork = false,
        public bool $supportsSignals = false,
        public bool $supportsAsyncIo = false,
        public bool $supportsCoroutines = false,
        public bool $supportsGracefulReload = false,
        public bool $supportsWorkerRecycle = false,
        public bool $supportsHttp1 = false,
        public bool $supportsHttp2 = false,
        public bool $supportsHttp3 = false,
        public bool $ownsHttp1Wire = false,
        public bool $ownsHttp2Wire = false,
        public bool $ownsHttp3Wire = false,
        public bool $supportsTlsAlpn = false,
        public bool $supportsQuic = false,
        public bool $supportsWebsocket = false,
        public bool $supportsOpcache = false,
        public bool $supportsOpcacheCli = false,
    ) {}

    /**
     * @return array<string, bool|string>
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver->value,
            'persistent_process' => $this->persistentProcess,
            'persistent_application' => $this->persistentApplication,
            'owns_listener' => $this->ownsListener,
            'owns_event_loop' => $this->ownsEventLoop,
            'owns_worker_pool' => $this->ownsWorkerPool,
            'supports_fork' => $this->supportsFork,
            'supports_signals' => $this->supportsSignals,
            'supports_async_io' => $this->supportsAsyncIo,
            'supports_coroutines' => $this->supportsCoroutines,
            'supports_graceful_reload' => $this->supportsGracefulReload,
            'supports_worker_recycle' => $this->supportsWorkerRecycle,
            'supports_http1' => $this->supportsHttp1,
            'supports_http2' => $this->supportsHttp2,
            'supports_http3' => $this->supportsHttp3,
            'owns_http1_wire' => $this->ownsHttp1Wire,
            'owns_http2_wire' => $this->ownsHttp2Wire,
            'owns_http3_wire' => $this->ownsHttp3Wire,
            'supports_tls_alpn' => $this->supportsTlsAlpn,
            'supports_quic' => $this->supportsQuic,
            'supports_websocket' => $this->supportsWebsocket,
            'supports_opcache' => $this->supportsOpcache,
            'supports_opcache_cli' => $this->supportsOpcacheCli,
        ];
    }
}
