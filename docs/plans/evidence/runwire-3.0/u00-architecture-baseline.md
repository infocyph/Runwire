# U00 — Runwire 3.0 source/API baseline and unified-architecture decision record

**Status (2026-10-23):** U00 architecture audit in progress. This is a source-grounded design document, **not implemented API**. Only the [3.0 checklist](../../runwire-3.0-unified-runtime-development-plan.md) controls completion. Source changes must preserve historical tests unless intentionally superseded and fully requalified.

**Published comparator:** Runwire `2.1.2`, immutable commit `7e9bf20ad0737a187dfed77b723791b141b36378`. **Development head inspected:** `7bfd36629fe856ebb1986268d32b976fc9e4fd1a`. Both checked within **infocyph/Runwire only**. Archive of old QA: [2.2 evidence](../runwire-2.2/README.md); [original 2.2 plan, immutable Git history](https://github.com/infocyph/Runwire/blob/7afd11e21ec4e64c610885f5f6ba42311b61c4d0/docs/plans/runwire-2.2-runtime-improvement-plan.md).

## A. Source-root and public API audit checkpoints (U00.01 in progress)

**U00.01 ACCEPTED:** published `2.1.2` public signatures exported from **350 PHP source files / 350 types** by the repeatable source-only exporter. Permanent [checksum lock](public-api-2.1.2.lock.json) pins sha256 `a2348788547bb8e3c89a49db399862afc6a13f18fb0aa5470d27f89780871dd1`, enforced by [Source Audit #39904006992](https://github.com/infocyph/Runwire/actions/runs/39904006992); [full signature artifact](https://github.com/infocyph/Runwire/actions/runs/39904006992/artifacts/12479892848) preserves declarations. The source tag, exporter source and checksum are immutable/reconstructable.

| Published 2.1.2 public API file | Immutable Git blob | Existing contract and required 3.0 audit |
| --- | --- | --- |
| `src/Network/Connection.php` | `7a2b4a4334f77f1596da20722463ca9ece9cb3de` | `final class Connection` directly accepts `LoopInterface`, live stream resource, `ConnectionLimits`, peer/local addresses, negotiated protocol, TLS flag and budget; `write(string): WriteResult`; factory transition is potentially breaking |
| `src/Coroutine/Network/AsyncConnection.php` | `b44df8f26efc1bc08c9eabe46a38e102eac10ce1` | `__construct(Connection, CoroutineScope)`, `receive(int): string`, `write(string): WriteResult`, `close(): CloseReason`; callback transfer and ownership must be migrated intentionally |
| `src/Loop/LoopInterface.php` | `34e4794e5ba21d43045a9000c28cb6a34b8a2b31` | Existing `onReadable`, `onWritable`, `delay`, `repeat`, `defer`, `cancel`, `now`, `run`, `stop`. Avoid adding abstract methods unless genuinely required |
| `src/Network/TlsOptions.php` | `f37fca306f3a4903d9f45e8618289d21bf230520` | Server-side cert/key/ALPN options; `verify_peer=false`, `verify_peer_name=false` defaults are server policy **not acceptable as outbound client defaults** |
| `src/Network/ConnectionLimits.php` | `a9678e18820f8c2181597e491dd3e60f14f23a6b` | Existing bounded read/send buffers, watermarks, idle and lifetime deadlines must be retained in new engine |
| `src/RuntimeCapabilities.php` | `a216d23197a57047b639b56defeec7b78783f3b3` | Explicit listener/event loop/worker ownership and H1/H2/H3/TLS/QUIC/coroutine capabilities; expand truthfully for outbound/file/autoscaling |
| `src/Runtime/RuntimeDriverResolver.php` | `87ca8adb78c9f2ac526da05df363c9c9bda35b93` | AUTO chooses active host, otherwise native CLI; Swoole must be active or explicitly requested |
| `src/RuntimeOptions.php` | `07c388bd57e1c4754d52279f2fc6903e0f1980a3` | Constructor contains driver/opcache/recycle/request/admission/diagnostics/host/reload policies; changes to required or positional parameters are potentially breaking |

**Next U00.01 step:** export all 2.1.2 public classes/interfaces/traits/enums, properties, methods/parameter types/defaults, return types and enum cases with a repeatable source-only analyzer; pin resulting manifest file hash and verify independent runs match. Do not claim these eight spot-checked files are the full public API.

## B. Historical 2.2 completed work: disposition by every task group (U00.02)

The [historical 2.2 plan](https://github.com/infocyph/Runwire/blob/7afd11e21ec4e64c610885f5f6ba42311b61c4d0/docs/plans/runwire-2.2-runtime-improvement-plan.md) contained exactly **95 task IDs: 75 checked, 20 open**. Historical checkmarks are **not** 3.0 acceptance.

| Historical group | Done / total | 3.0 disposition and requalification |
| --- | ---: | --- |
| R0 benchmark/preflight | 9/11 | **Carry forward** workload fixtures, CPU affinity and QA scripts; **re-run** baseline after architecture changes; unresolved R0.06/.07 → U15 |
| R1 lifecycle/dispatch | 7/8 | **Retain** request context and callback isolation coverage; **rewrite only if beneficial**; R1.08 → U15 |
| R2 event loop/Fibers | 9/9 | **Retain and requalify** 256-timer Select fairness, scheduler/queue pressure, cancellation fixtures; new loop/executor U01/U06 |
| R3 H1 and buffers | 8/9 | **Retain and requalify** ByteQueue batching and H1 parser/write tests; actual TLS/syscall cost R3.09 → U08/U15 |
| R4 H2/H3 protocols | 7/10 | **Retain** real HPACK/QPACK/QUIC pressure and peer-reset regression fixtures; **rework** unified transport and profile U08/U15 |
| R5 outbound I/O | 2/9 | Existing `AsyncConnection` becomes an **architectural input**, not a separate new dialer stack; seven previously deferred IDs → U02–U04/U13/U15 |
| R6 supervisor and hosts | 9/10 | **Retain** six real hosted PHP8.4/8.5 fixtures and crash/reload QA; **requalify** active multi-protocol stop and native autoscaling U12/U13 |
| R7 metrics/security | 10/10 | **Retain** process-tree RSS/FD, PHP heap/GC/OPcache and security standards; expand to all 3.0 outbound/filesystem U14/U15 |
| R8 release certification | 8/10 | **Retain** benchmark tooling, isolated Composer consumer and real host acceptance; **re-run** full 3.0 exact-source release gates U17 |
| R9 release handoff | 6/9 | **Preserve as historical docs/evidence**, supersede obsolete 2.2 publication target; final QA/risk approval and release U17 |

**Totals:** 75 historical checked, 20 historical unchecked. The 3.0 roadmap's **20/20 crosswalk** includes all old open items. Source-level changes may invalidate even previously passing behavior; preserve tests and re-run as new 3.0 functionality is implemented.

## C. Source-backed runtime/capability ownership inventory (U00.08)

Current [`RuntimeDriver`](https://github.com/infocyph/Runwire/blob/2.1.2/src/Runtime/Enum/RuntimeDriver.php) enumerates AUTO, NATIVE, SWOOLE, ROADRUNNER, FRANKENPHP, FPM. The resolver recognizes actual hosts and selects a native CLI mode otherwise. [`RuntimeCapabilities`](https://github.com/infocyph/Runwire/blob/2.1.2/src/RuntimeCapabilities.php) distinguishes `ownsListener`, `ownsEventLoop`, `ownsWorkerPool`, `hostOwnsEventLoop`, `supportsRunwireCoroutines`, `supportsQuic`, and per-protocol wire ownership; inspect resolved flags per real mode rather than hardcode a claim that all drivers support all features.

| Driver/mode | Listener and wire owner | Event loop and worker ownership | Current constraints | New 3.0 requirement |
| --- | --- | --- | --- | --- |
| Native prefork CLI | Runwire binds and accepts | Runwire worker pool and selected native loop | Requires PCNTL/POSIX; H2 TLS needs OpenSSL; H3 needs supported QUIC extension | Full dial/async file/QUIC and native autoscaling with fork-safe resources |
| Native portable CLI | Runwire | Runwire single process; Select fallback supported | No prefork signals/autoscale claim; SelectLoop has 256 native connection cap | Full supported async network/file operations, explicit autoscale unavailable |
| Native ext-event | Runwire | Runwire event loop, optional ext-event | ext-event optional; SelectLoop fallback | Same public I/O semantics, no mandatory ext-event |
| Swoole/OpenSwoole host | Host | Host-owned reactor; Runwire adapter, explicit selection | Do not create a competing event loop | Dial/file/client operations through host-compatible scheduler |
| RoadRunner | Host HTTP/worker transport | Host-managed persistent worker | No Runwire worker-pool control | Request lifecycle, persistent pooling and shutdown with clean ownership |
| FrankenPHP | Host HTTP/worker transport | Host/mode-dependent | Worker mode vs request mode differs | Distinguish mode and resource lifetime; no fake prefork control |
| PHP-FPM | Web server and FPM | Request-scoped execution; host owns pool | No persistent Runwire listener/worker control | Request-scoped outbound async API if scheduler available; explicit unsupported capabilities |

**Optional capabilities:** `ext-event` for scalable event loop; `ext-openssl` for TLS/H2 ALPN; `ext-quic` for native QUIC/H3; `ext-pcntl`+`ext-posix` for native prefork; `ext-sockets` for low-level socket features; `ext-swoole`/`ext-openswoole` for host adaptation. They are suggestions/capability checks, **not mandatory Composer requirements**. A future async filesystem implementation must use genuinely off-loop execution and document missing backend behavior. **This table is an inventory of existing scope and a target, not a claim that 3.0 functions already work.**

## D. Unified architecture decisions and proposed API migration (U00.04–U00.07 in progress)

### Layer boundaries and state transitions

1. Pending DNS resolution: `NEW → RESOLVING → CONNECTING`; zero established-byte-stream visibility; cancellation and DNS errors terminate only the attempt.
2. Outbound TCP/Unix: `CONNECTING → HANDSHAKING` (TLS) or `CONNECTING → OPEN` (plain), after verifying socket success. Losing Happy Eyeballs attempts are closed once.
3. Accepted server TLS: listener owns the accepted transport until TLS handshake succeeds; established connection is factory-created once.
4. Established stream: `OPEN → HALF_CLOSED_READ / HALF_CLOSED_WRITE → DRAINING → CLOSED`, with optional direct `OPEN → CLOSED` abort. Distinguish EOF from error and from cancellation.
5. QUIC: own transport connection and independent streams; per-stream reset/STOP_SENDING must not close healthy sibling streams. Do not conflate QUIC streams and datagrams with TCP byte streams.
6. UDP: datagram unit with peer metadata and message boundaries; **never** subclass ordered TCP stream for convenience.
7. Worker/scope close: cancel pending DNS/dial/TLS, drain admitted work, evict pools, resolve file executor jobs according to policy, and close every owned FD/watcher exactly once.

**Unverified design:** U00.04 stays OPEN until the state diagrams and illegal-transition executable tests pass.

### One factory, one established-stream core

Existing Runwire accepted streams currently instantiate `Connection` directly, and `AsyncConnection` wraps a completed `Connection` with coroutine callbacks. For 3.0, the listener and dialer must pass completed, ownership-checked sockets into the **same** factory and established I/O owner. Do not hand pending or partially verified TLS sockets to an established connection or duplicate buffering logic in dialer.

**Illustrative 3.0 API proposal, NOT CODE IMPLEMENTED:**

```php
// 2.1.2: constructing a Connection manually from an already-open stream.
$connection = new Connection($loop, $stream, $limits);

// 3.0 proposal: factory controls stream handoff, ownership and limits.
$connection = $connectionFactory->adopt($stream, $loop, $scope, $limits);

// 3.0 proposal: dialer connects and negotiates before handing off.
$connection = $scope->dial('tls://example.org:443', $options);
```

**Breaking changes under consideration:** restrict public `Connection::__construct`; replace or narrow manual `AsyncConnection` callback ownership; introduce safe **client** TLS options instead of inheriting server `TlsOptions` (which defaults `verify_peer` and `verify_peer_name` false); explicitly add resource capability semantics to RuntimeContext/CoroutineScope; revisit LoopInterface only if platform behavior demands it. No public signature has been changed yet.

### Error, closure and resource contracts to finalize

- Typed deadline: DNS/connect/TLS/total timeouts are distinct; race resolution must produce exactly one outcome.
- Half-close: a read EOF does not drop buffered outbound writes; no automatic new reads after EOF.
- Backpressure: watermarks, queued bytes and pending operations are bounded per connection and at worker/global level; no infinite retries or writes after closure.
- Cancellation: watcher/timer/resource ownership belongs to one scope/worker; a cancelled request's outbound socket must be released or transferred explicitly.
- Client TLS: hostname and certificate verification default on, even if server-side TLS validation policy differs.
- Hosted execution: no silently blocking fallback for DNS/disk and no Runwire-created competing Swoole reactor.

**U00.06/.07 design contracts ACCEPTED** by source-backed [migration draft](u00-public-api-migration-draft.md) and [resource contract](u00-resource-state-contract.md). Runtime behavior and executable contract implementation remain subject to later U02–U06 work. **U00.04/.05 remain OPEN** pending real illegal-transition tests and verified single factory ownership.

## E. Baseline performance, gate provenance and unfinished U00

Previous matched certification:
- [Historical H1 certificate #39462685239](https://github.com/infocyph/Runwire/actions/runs/39462685239) was PASS on an older `2.2` source snapshot, not 3.0.
- [Release Certification #39891900634](https://github.com/infocyph/Runwire/actions/runs/39891900634) on historical source `8b125bab` was **overall FAILED** for excessive paired-run CV >2.5%; its independent 30-minute soak passed.
- The 3.0 benchmark must pin published 2.1.2 and frozen candidate to the same physical server CPU, load generator on a different permitted physical core, record CPU topology/affinity and server/client versions, use 30s warmups and five alternating 180s pairs with <2.5% both CV, plus the full 1800s process-tree soak, real H2/H3 and hosted clients.

**U00.09 remains OPEN** until actual measured exact runner topology, load-generator headroom and immutable evidence are recorded (historical source does not qualify automatically).

### Scope authorization (U00.10)

The maintainer selected **Runwire 3.0** with the full agreed client/server/QUIC/WebTransport/filesystem/autoscale scope, and explicitly required the **existing branch and draft PR #9**, one canonical task tracker, and no changes to Foundation/Infbyte or independent Rust siblings. Sphinx/Read the Docs full feature/API reference is mandatory. The PR and README now reference [3.0 roadmap](../../runwire-3.0-unified-runtime-roadmap.md) and [175-task detailed plan](../../runwire-3.0-unified-runtime-development-plan.md). Release remains draft/unmerged/unpublished.

## F. U00 acceptance audit (2026-10-23)

**Accepted with proof:** **U00.01** pinned 350-type manifest and source-only exporter, **U00.02** 10-group historical disposition, **U00.03** complete 20/20 old-ID task mapping, **U00.06** migration draft, **U00.07** normative transport/termination contract, **U00.08** exact existing mode/capability matrix, and **U00.10** maintainer-approved scope/PR. See [Source Audit #39904006992](https://github.com/infocyph/Runwire/actions/runs/39904006992), [all PHPForge QA/analysis lanes in Security #39904007423](https://github.com/infocyph/Runwire/actions/runs/39904007423), [real-host #39904006975](https://github.com/infocyph/Runwire/actions/runs/39904006975), and source-linked documents.

**Still OPEN:** U00.04 illegal-transition executable tests, U00.05 production single-factory ownership verification, U00.09 physical runner/core and load-generator headroom inventory. These cannot be checked from diagrams, a draft, or historical throughput data alone.

**Canonical U00 tracker: 7/10; overall 3.0: 7/175 (4.0%).** Source and audited designs are accepted as scope/baseline work, **not** as completion of any future networking client, QUIC, filesystem or autoscaling implementation.
