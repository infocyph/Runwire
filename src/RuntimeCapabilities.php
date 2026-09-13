<?php

declare(strict_types=1);

namespace Infocyph\Runwire;

use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\SystemResources;

final readonly class RuntimeCapabilities
{
    public function __construct(
        public RuntimeDriver $driver,
        public bool $persistentProcess = false,
        public bool $persistentApplication = false,
        public bool $ownsListener = false,
        public bool $ownsEventLoop = false,
        public bool $ownsWorkerPool = false,
        public bool $hostOwnsEventLoop = false,
        public bool $runwireLoopAvailable = false,
        public bool $supportsFork = false,
        public bool $supportsSignals = false,
        public bool $supportsAsyncIo = false,
        public bool $supportsRunwireCoroutines = false,
        public bool $hostNativeCoroutines = false,
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
        public bool $supportsReusePort = false,
        public bool $supportsUnixSockets = false,
        public bool $supportsPrivilegeDrop = false,
        public SystemResources $resources = new SystemResources(),
    ) {}

    public function supports(RuntimeCapability $capability): bool
    {
        return match ($capability) {
            RuntimeCapability::CONCURRENT, RuntimeCapability::RUNWIRE_COROUTINES => $this->supportsRunwireCoroutines,
            RuntimeCapability::HOST_NATIVE_COROUTINES => $this->hostNativeCoroutines,
            RuntimeCapability::HOST_OWNS_EVENT_LOOP => $this->hostOwnsEventLoop,
            RuntimeCapability::OWNS_EVENT_LOOP => $this->ownsEventLoop,
            RuntimeCapability::OWNS_LISTENER => $this->ownsListener,
            RuntimeCapability::OWNS_WORKER_POOL => $this->ownsWorkerPool,
            RuntimeCapability::PERSISTENT => $this->persistentApplication,
            RuntimeCapability::RUNWIRE_LOOP_AVAILABLE => $this->runwireLoopAvailable,
            RuntimeCapability::SUPPORTS_GRACEFUL_RELOAD => $this->supportsGracefulReload,
            RuntimeCapability::SUPPORTS_HTTP1 => $this->supportsHttp1,
            RuntimeCapability::SUPPORTS_HTTP2 => $this->supportsHttp2,
            RuntimeCapability::SUPPORTS_HTTP3 => $this->supportsHttp3,
            RuntimeCapability::SUPPORTS_WORKER_RECYCLE => $this->supportsWorkerRecycle,
        };
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver->value,
            'persistent_process' => $this->persistentProcess,
            'persistent_application' => $this->persistentApplication,
            'owns_listener' => $this->ownsListener,
            'owns_event_loop' => $this->ownsEventLoop,
            'owns_worker_pool' => $this->ownsWorkerPool,
            'host_owns_event_loop' => $this->hostOwnsEventLoop,
            'runwire_loop_available' => $this->runwireLoopAvailable,
            'supports_fork' => $this->supportsFork,
            'supports_signals' => $this->supportsSignals,
            'supports_async_io' => $this->supportsAsyncIo,
            'supports_runwire_coroutines' => $this->supportsRunwireCoroutines,
            'host_native_coroutines' => $this->hostNativeCoroutines,
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
            'supports_reuse_port' => $this->supportsReusePort,
            'supports_unix_sockets' => $this->supportsUnixSockets,
            'supports_privilege_drop' => $this->supportsPrivilegeDrop,
            'effective_cpu_count' => $this->resources->effectiveCpuCount,
            'effective_memory_bytes' => $this->resources->effectiveMemoryBytes,
        ];
    }
}
