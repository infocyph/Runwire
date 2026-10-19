# Runwire 2.2 — R0 runtime contract inventory

**Status:** Contract inventory in progress; generated full-symbol snapshot runs in benchmark CI.
**Baseline:** 2.1.2 / `7e9bf20ad0737a187dfed77b723791b141b36378`
**Candidate evidence:** `benchmarks/public_api_snapshot.php` emits full public/protected signatures (methods, parameter types/defaults, return types, properties) per PHP version. Its JSON is a CI artifact; do not commit the bulky snapshots.

## API ownership boundaries (source confirmed)

| Contract | Current behavior and important default | Where to assert |
| --- | --- | --- |
| `Runtime::create(?RuntimeOptions)` | Auto driver, explicit capability validation and hosted-native selection | `RuntimeTest`, `RuntimeSelectorTest` |
| `Runtime::listen(Server/StreamServer/DatagramServer)` | Native listener ownership only; separate from hosted `serve` | `RuntimeTest`, `RuntimeCapabilityResolverTest` |
| `Runtime::serve(callable,...)` / `serveApplication(RuntimeApplicationFactoryInterface)` | Host owns listener/reactor; application factory constructs under worker context | `HostRuntimeTest`, `SwooleDriverTest` |
| `RuntimeApplicationInterface` | `start`, `handle`, `drain`, `cancelActive`, `healthy`, `healthFailure`, `shutdown`, metrics | `ApplicationLifecycleTest` |
| `RequestContext` | Request-local attributes, one-time activation, deadline/cancellation, terminal `complete`, owned-work settlement | `RequestContextTest`, `RequestContextActivationTest` |
| `HttpRequest` | Readonly normalized method/target/version/headers/body, optional endpoints and encryption; streaming body observes cancellation | `HttpProtocolSemanticParityTest` |
| `ResponseWriterInterface` | `start(200, null)`, `write`, `end('')`, `onDrain`, `onTerminal`; terminal once and bounded writes | `ResponseSemanticsTest` |
| `LoopInterface` | Timers, deferred callbacks, readable/writable watchers, monotonic clock, run/stop | `EventLoopTest`, `SelectLoopTest` |
| `ConnectionLimits` | 64KiB read chunk, 256KiB per-tick read/write, 1MiB max send/receive buffer, watermarks 256/512KiB | `ConnectionTest` |
| `CoroutinePolicy` | 1,024 max tasks, 1,024 ready backlog, 128 resumes/tick, bounded future/primitive waiters | `BatchLCoroutineSchedulerTest` |
| `Http1Limits` | 8KiB request/field line, 64KiB headers, 16MiB body, 1,000 keepalive requests, FIXED scheduling default | `Http1ConnectionTest` |
| `RuntimeOptions` | AUTO driver, AUTO OPcache; explicit bounded policies and host options | `RuntimeOptionsTest` |

## Capability/runtime matrix (source and workflow reviewed)

| Mode | Listener/reactor/worker ownership | Optional prerequisite / caveat |
| --- | --- | --- |
| CLI prefork | Runwire listener, ext-event or SelectLoop, Runwire workers | PCNTL/POSIX; supervisor/recycle supported |
| CLI portable | Runwire listener, shared SelectLoop, single process | PCNTL absent; prefork-only settings explicitly rejected; 256 native SelectLoop connection ceiling |
| FPM | Host-managed server/worker | Request-scoped lifecycle; no Runwire-managed listener |
| FrankenPHP classic / worker | Host-managed | Application lifetime depends on actual host mode |
| RoadRunner | Host-managed | No competing reactor/worker pool |
| Swoole / OpenSwoole | Host reactor/worker; Runwire adapter | Explicit SWOOLE driver unless active hosting proven |
| HTTP/2 TLS | Native protocol when selected and supported | OpenSSL/TLS and ALPN |
| HTTP/3 | Native HTTP/3 over ext-quic | Supported QUIC/OpenSSL ABI required; must fail closed when explicitly configured without extension |

## Validation requirements

- Compare generated full-symbol manifests on PHP 8.4 and 8.5 and inspect intentional differences, if any.
- Preserve single-use request context and exactly-once response terminal ownership, including exception/cancellation/drain.
- Keep 2.2 API compatible by default; any intentional break requires explicit decision, migration and tests.
- Hosted driver mode remains capability-first. Installed Swoole does not itself imply active hosting.
- A complete machine-readable source inventory is **not** equivalent to runtime behavior or real-host validation.

