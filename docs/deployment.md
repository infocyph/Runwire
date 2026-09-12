# Runwire 1.0 Deployment and Tuning

Runwire is a framework-agnostic process and network runtime. It can own native HTTP wire handling or adapt an application to an existing PHP host runtime. These modes have different ownership and deployment requirements and should not be mixed inside one process.

## Runtime ownership

| Mode | Listener / wire owner | Runwire responsibility |
| --- | --- | --- |
| `native` | Runwire | event loop, TCP/TLS, HTTP/1.1, HTTP/2, optional QUIC/HTTP/3, worker lifecycle and generic recycle policy |
| `fpm` | PHP-FPM / web server | normalize one host request, dispatch, cleanup, emit host response; persistent-worker recycling is not owned by the request process |
| `frankenphp` | FrankenPHP | classic or persistent-worker request adaptation with generic Runwire recycle accounting |
| `swoole` | Swoole/OpenSwoole | request adaptation; host owns server loop and native request-count recycling while Runwire applies generic memory/lifetime policy where supported |
| `roadrunner` | RoadRunner | request/session adaptation with generic Runwire recycle accounting |
| `auto` | detected environment | select a valid hosted mode or native mode according to runtime configuration |

Hosted modes must not start a competing Runwire listener, event loop, worker pool, or HTTP server. Capability reporting distinguishes protocol support from Runwire wire ownership.

## Native HTTP protocol stack

Runwire 1.0 supports:

- HTTP/1.1 over TCP or TLS;
- HTTP/2 over TLS ALPN (`h2`) with HTTP/1.1 fallback (`http/1.1`);
- HTTP/3 over QUIC v1 / TLS 1.3 with ALPN `h3` when the optional QUIC capability is available.

HTTP/1.1, HTTP/2 and HTTP/3 dispatch the same application-visible `HttpRequest` / `ResponseWriterInterface` contract. Protocol framing, HPACK/QPACK, stream management and transport errors remain runtime concerns.

## HTTP/3 and QUIC

The ordinary Composer installation does not require QUIC. Native HTTP/3 is capability-based and must fail fast when selected without a supported QUIC engine.

The Runwire 1.0 CI-backed QUIC adapter uses `mikepultz/php-quic` (`ext-quic`). The current native adapter requires an OpenSSL 3.5+ QUIC-capable baseline. Deployments enabling HTTP/3 must provide:

1. the supported QUIC extension/engine;
2. TLS certificate and private-key material;
3. UDP reachability for the selected listener port;
4. ALPN `h3` capability;
5. resource limits appropriate for expected QUIC connection and stream concurrency.

Runwire delegates QUIC cryptography, congestion control and loss recovery to the maintained native QUIC engine. It does not implement those algorithms in PHP.

### 0-RTT and peer addresses

0-RTT application dispatch is disabled for Runwire 1.0. Replay-unsafe requests are therefore not silently promoted into ordinary trusted application requests.

QUIC connection migration or address rebinding may change peer-address metadata during a connection. Do not use peer-address stability as an authentication or authorization boundary.

## Graceful shutdown, reload and worker recycling

Native shutdown follows a bounded drain model:

1. stop accepting new connections;
2. HTTP/2 and HTTP/3 enter drain state and send GOAWAY where applicable;
3. already-admitted requests/streams are allowed to complete within configured bounds;
4. remaining connections are closed when the drain deadline expires;
5. supervised workers are reaped before shutdown completes.

Rolling supervisor reload starts replacement generation capacity before retiring the previous generation. Application resources created after fork must remain generation/worker-owned and must not be inherited from an application-connected parent.

Persistent worker recycling is configured through `RuntimeOptions::workerRecycle` using the generic `WorkerRecyclePolicy`. Request-count, lifetime and memory thresholds are soft retirement triggers evaluated at safe request boundaries; they do not kill active application work immediately. Request and lifetime jitter can stagger retirement so workers started together do not all recycle at the same threshold.

A deployment may explicitly configure values such as:

```php
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Runwire\Supervisor\WorkerRecyclePolicy;

$options = new RuntimeOptions(
    workerRecycle: new WorkerRecyclePolicy(
        maxRequests: 10_000,
        maxLifetimeSeconds: 3_600,
        maxMemoryBytes: 268_435_456,
        jitterRequests: 500,
        jitterSeconds: 120,
        gracefulTimeoutSeconds: 10.0,
    ),
);
```

All recycle thresholds default to disabled. This avoids silently imposing operational limits on applications before they have measured their workload. FPM is request-scoped from Runwire's point of view and therefore does not pretend to apply persistent-worker recycle dimensions. Swoole/OpenSwoole keeps native `max_request` enforcement for the request-count dimension, including native grace/jitter, while Runwire uses the same generic policy contract for the remaining supported dimensions.

## Default protocol ceilings

Defaults are intentionally conservative. Increase them only after measuring memory, file-descriptor and latency behavior in the real deployment.

| Area | HTTP/1.1 | HTTP/2 | HTTP/3 |
| --- | ---: | ---: | ---: |
| Request body | 16 MiB | 16 MiB | 16 MiB |
| Header / field-section bytes | 64 KiB | 64 KiB | 64 KiB |
| Header fields | 100 | 100 | 128 |
| Concurrent streams | n/a | 100 | 100 |
| Lifetime request streams | keep-alive: 1,000 | 10,000 | 10,000 |
| Pending response per stream | bounded by response writer | 1 MiB | 1 MiB |
| Pending response per connection | bounded connection buffer | 8 MiB | 8 MiB |
| Compression table | n/a | HPACK 4 KiB | QPACK 64 KiB maximum |
| Blocked compression streams | n/a | n/a | 32 |

Additional bounded defaults include HTTP/2 control-frame work, continuation count, response wire queue, HTTP/3 peer unidirectional-stream churn, QPACK blocked bytes, QPACK encoder queue bytes, reads/writes per pump and inbound bytes per pump.

The constructor validation in `Http1Limits`, `Http2Limits` and `Http3Limits` is authoritative. Invalid watermark relationships or non-positive hard ceilings are rejected before serving traffic.

## Backpressure tuning

Treat backpressure ceilings as protection boundaries, not throughput targets.

- Keep low/high watermarks separated enough to avoid pause/resume thrashing.
- Do not raise per-stream response buffering without considering aggregate connection buffering and worker concurrency.
- Raising HTTP/2 or HTTP/3 concurrent-stream counts multiplies per-connection state and should be paired with connection-level limits.
- Larger HPACK/QPACK dynamic tables may improve compression at the cost of retained state and more expensive churn.
- Large request-body allowances should not imply equally large in-memory pending-body buffers; Runwire streams bodies and maintains independent pending-buffer ceilings.
- Increase HTTP/3 per-pump read/write budgets only when event-loop fairness remains acceptable under multiplexed load.

## Event loop and worker sizing

`SelectLoop` is the portable native baseline. `ext-event` is optional for deployments that benefit from a different event backend.

Worker count should be chosen from measured CPU saturation, blocking application work and memory per worker. More workers do not compensate for unbounded application blocking. For persistent runtimes, configure `WorkerRecyclePolicy` from observed request volume, retained memory and expected worker lifetime; keep per-request cleanup enabled regardless of the host runtime.

## Reverse proxies and load balancers

When a proxy terminates HTTP/TLS/QUIC, the proxy owns that wire protocol. Runwire should be configured for the actual downstream mode rather than claiming native wire ownership it does not have.

For native HTTP/3, the load-balancing path must support UDP/QUIC to the Runwire listener. A TCP-only forwarding path cannot carry native HTTP/3. HTTP/3 capability advertised by an upstream proxy does not mean the downstream Runwire process owns HTTP/3.

## Security and operations checklist

Before production rollout, verify that:

- no untrusted value is passed through an implicit shell path;
- process children are bounded, supervised and reaped;
- request, header, stream, frame, body and response limits are explicitly reviewed;
- TLS key material has appropriate filesystem permissions;
- HTTP/3 0-RTT remains disabled unless a future replay-safety policy explicitly enables it;
- persistent application state is cleaned after every request, including failed handlers;
- persistent worker recycle thresholds and jitter are explicitly reviewed rather than assumed;
- sensitive argv, environment and HTTP values are not logged by default;
- application DB/cache/broker connections are created in the correct post-fork worker lifetime;
- shutdown and reload deadlines are exercised before production traffic is enabled.

## Validation before rollout

Use the same supported PHP versions and optional capabilities as production. At minimum run the full PHPForge QA matrix, the QUIC-present HTTP/3 lane when enabling native HTTP/3, the portable soak/fault suite and the benchmark workflow. Benchmark results are evidence for regression tracking, not universal capacity claims; see `docs/benchmarks.md`.
