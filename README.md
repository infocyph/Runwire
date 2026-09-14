# Runwire

A high-performance process and network runtime for PHP.

Runwire 1.0 is being finalized for the Foundation 3 launch. It provides the low-level runtime boundary for process supervision, event loops, network servers, native HTTP/1.1/2/3, host-runtime adaptation, structured coroutines and persistent application lifecycle while remaining framework agnostic.

## Baseline

- 64-bit PHP `^8.4`
- `ext-pcntl`
- `ext-posix`
- PHPForge `dev-main@dev` for development QA

Runwire requires a 64-bit PHP build because monotonic nanosecond deadlines and QUIC/HTTP/3 variable-length integers rely on integer ranges unavailable on 32-bit PHP.

Optional runtime capabilities such as native TLS/ALPN, custom accelerated event-loop adapters, privilege reduction, reuse-port and QUIC are detected and validated separately. Ordinary Runwire installation remains valid without QUIC; selecting native HTTP/3 fails fast when the required QUIC capability is unavailable.

## Runtime model

Runwire owns generic runtime mechanics only. Foundation owns application/container semantics, Webrick owns application HTTP semantics, and Omnibus owns messaging/queue semantics.

Runwire 1.0 provides:

- prefork worker supervision, readiness, restart/recycle and bounded rolling reload;
- immutable `RuntimeContext` plus isolated `RequestContext`, deadlines and cancellation;
- PHP Fiber structured coroutines with task ownership, cancellation propagation, bounded channels/synchronization and host-loop integration;
- application factory and boot/warmup/handle/reset/drain/shutdown lifecycle contracts;
- bounded admission, worker recycling, cgroup-aware sizing and resource policy;
- named timer/task/service worker support and a development-only reload watcher;
- fixed-cardinality metrics, diagnostics and bounded control status;
- native TCP/TLS HTTP/1.1 and HTTP/2 plus optional QUIC/HTTP/3;
- hosted FPM, FrankenPHP, Swoole/OpenSwoole and RoadRunner adapters without competing listener/event-loop ownership;
- capability-first APIs so integrations can ask what the runtime supports instead of branching on a driver name.

## Documentation

- [`docs/deployment.md`](docs/deployment.md) — runtime/capability ownership, application lifecycle, contexts/deadlines, reload/recycle, observability, admission/resource policy, HTTP/1.1/2/3, QUIC/QPACK and production tuning.
- [`docs/coroutines.md`](docs/coroutines.md) — coroutine mental model, structured ownership, cancellation, synchronization, host integration, network adaptation, diagnostics, tuning and migration guidance.
- [`docs/benchmarks.md`](docs/benchmarks.md) — protocol/lifecycle/host benchmark methodology, comparative-evidence rules and native HTTP/3 transport measurement.
- [`docs/plans/runwire-1.0-foundation-3-launch-plan.md`](docs/plans/runwire-1.0-foundation-3-launch-plan.md) — canonical Runwire 1.0 development and release plan.

## Performance claims

Runwire benchmark artifacts are regression and workload-specific evidence. Do not infer a universal "fastest PHP runtime/framework" claim from one CI runner, a protocol microbenchmark, or Runwire-only measurements. Cross-runtime comparisons must use equivalent real runtimes, hardware, PHP version, protocol, workload, worker count and concurrency and must report throughput together with latency percentiles, error rate, CPU and RSS.
