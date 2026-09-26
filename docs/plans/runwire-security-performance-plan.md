# Runwire 2.0 security, lifecycle and sustained-performance plan

Audit date: 2026-09-25. Source revision: `7ab48fcf224ee86838e5bf82a50b998c2aaa8a90` (local tag `1.0`).

Target: **2.0.0**, consolidating the security, correctness and runtime-contract work into one major release. Status: broad review and release plan complete; implementation and release certification remain open. This document does not certify the absence of vulnerabilities.

## Implementation tracker

Updated: 2026-09-26. Working branch: `feature/next-edition`. Implementation baseline: `1bd9ae9a00f352714070177e4cfb01c811231b0e`.

This tracker is part of the implementation record. Update it in every implementation batch; do not mark a batch complete until its code, targeted regression evidence and relevant quality checks are present on the branch. Keep the pull request open throughout implementation so CI and review findings feed back into the remaining batches.

| Batch | Scope | Status | Exit evidence |
| --- | --- | --- | --- |
| B0-A | Plan tracker and implementation PR | Complete | Tracker committed; PR opened before production changes |
| B0-B | Deterministic regressions for RW-01–09 and RW-17–21 | Complete — all reproduced findings have durable regression coverage; QA rolling | Reproductions committed in existing suites; bounded subprocesses where required |
| B0-C | 2.0 lifecycle, response, reset-retirement and coroutine ownership contracts | Complete | State transitions and public migration decisions recorded |
| B0-D | Per-driver capability/policy ownership matrix; baseline resource/performance budgets; F-04/F-05 decisions | Complete — capability/policy decisions certified with B2 host matrix QA | Driver table, supported matrix, budget evidence and feature decisions recorded |
| B1-A | HTTP/1 continuation/framing/timeouts — RW-01/02/03 | Complete — exact-head QA green | Targeted adversarial regressions green |
| B1-B | Select loop/timers/callback ownership — RW-04/17/21 | Complete for portable fallback — scalable capacity remains B3-A | Loop/descriptor/timer/UDP regressions green |
| B1-C | Error/TLS/Unix ownership hardening — RW-05/07/08 | Complete — exact-head QA green | Redaction, mTLS-policy and live-socket regressions green |
| B1-D | HTTP/3 body delivery and response framing — RW-19/18 | Complete for boundary/framing correctness — aggregate fairness remains B3-B | Fragmentation/coalescing and cross-writer response corpus green |
| B2-A | Terminal lifecycle, reset isolation and failure containment — RW-06/09/12/18 | Complete — terminal accounting, unhealthy latch, asynchronous terminal containment and worker retirement QA green | Common lifecycle suite green across native and hosts |
| B2-B | Shared-loop coroutine request scopes — RW-20 / F-02 | Complete — native loop attachment, concurrent progress, cancellation and scheduler-policy preservation QA green | Concurrent native requests, timers and cancellation progress together |
| B2-C | Truthful host capabilities and policy delegation — RW-22; GC disposition RW-14 | Complete — enabled-only host capability matrix and single lifecycle GC ownership QA green | Real-host capability matrix or explicit unsupported disposition |
| B3-A | Scalable native loop — F-01 / RW-04 capacity closure | Implemented — ext-event backend, fallback ceiling and >1024-descriptor lane added; exact-head QA running | Supported backend exceeds SelectLoop ceiling with bounded behavior |
| B3-B | HTTP/2/3 work accounting, deadlines and aggregate admission — RW-10/11/19 / F-03 | Implemented — per-turn H2 work, H3 control/progress limits, worker-wide byte budget and pressure metrics added; exact-head QA running | Fairness and worker-wide resource limits proven |
| B3-C | Process-tree/platform hardening — RW-13 | Implemented — process-group ownership, detached metadata, platform-null handling and repeated termination regressions added; exact-head QA running | Descendant/reap/cancellation/platform regressions green |
| B3-D | Duplication, CI provenance and accepted optional features — RW-15/16, F-04/F-05 if accepted | In progress — shared Content-Length validation owner added; workflow/package version refs preserved by project policy; dependency update cadence added; exact-head QA running | P2 dispositions recorded; version/tag refs preserved; update policy and accepted feature lanes green |
| B4 | Sustained-performance and release certification | Open | Production-equivalent baselines, soak, interoperability and full gates green |
| B5 | Migration docs, beta/RC evidence and exact-head final candidate | Open | All findings closed and final candidate matrix green |

### Finding tracker

| Finding | Priority | Batch | Status |
| --- | --- | --- | --- |
| RW-01 HTTP/1 parser continuation | High | B0-B / B1-A | Closed — exact-head QA green |
| RW-02 empty Transfer-Encoding framing | High | B0-B / B1-A | Closed — exact-head QA green |
| RW-03 silent/idle HTTP expiry | High | B0-B / B1-A | Closed — exact-head QA green |
| RW-04 SelectLoop descriptor-ceiling failure | High | B0-B / B1-B / B3-A | Portable fail-closed behavior QA green — scalable backend/capacity remains B3-A |
| RW-05 HTTP/2 exception-text disclosure | High | B0-B / B1-C | Closed — exact-head QA green |
| RW-06 failed reset does not retire worker | High | B0-B / B2-A | Closed — unhealthy latch and native/Swoole/host retirement QA green |
| RW-07 explicit TLS verification overwritten | P1 | B0-B / B1-C | Closed — exact-head QA green |
| RW-08 live Unix socket replacement | P1 | B0-B / B1-C | Closed — exact-head QA green |
| RW-09 streaming request lifetime mismatch | High | B0-B / B2-A | Closed — terminal lifecycle/admission ownership and cross-driver QA green |
| RW-10 HTTP/2 per-turn work accounting | P1 | B3-B | Implemented — bounded frame processing per loop turn; exact-head QA running |
| RW-11 HTTP/3 control/deadline accounting | P1 | B3-B | Implemented — shared control-byte accounting and request/QPACK progress deadlines; exact-head QA running |
| RW-12 inconsistent failure containment | P1 | B2-A | Closed — lifecycle failure containment and retirement QA green |
| RW-13 process-tree/detached cleanup | P2 | B3-C | Implemented — descendant process groups and detached cleanup retain tree ownership; exact-head QA running |
| RW-14 duplicated host GC ownership | P2 | B2-C | Closed — duplicate FrankenPHP/RoadRunner per-request GC removed; lifecycle policy is sole owner |
| RW-15 duplicated validation/security owners | P2 | B3-D | Implemented for Content-Length security parsing without merging protocol-specific semantics; exact-head QA running |
| RW-16 mutable CI/dependency provenance | P2 | B3-D | Disposition updated — preserve existing version/tag refs; automated dependency update cadence added; no SHA ref conversion |
| RW-17 stranded due timers | P1 | B0-B / B1-B | Closed — exact-head QA green |
| RW-18 inconsistent host response framing | P1 | B0-B / B1-D / B2-A | Closed — framing, terminal writer contract and lifecycle containment QA green |
| RW-19 HTTP/3 read-boundary body behavior | High | B0-B / B1-D / B3-B | Boundary-invariant bounded delivery QA green — aggregate fairness remains B3-B |
| RW-20 coroutine waits block native loop | P1 | B0-B / B2-B | Closed — native requests attach to the runtime-owned loop without nested driving; cancellation and policy QA green |
| RW-21 UDP callback close crash | P1 | B0-B / B1-B | Closed — exact-head QA green |
| RW-22 capability reporting exceeds enabled support | P1 | B0-D / B2-C | Closed — host capabilities now report enabled Runwire integration facts rather than host-product potential |

### Feature decision tracker

| Feature | Status | Decision point |
| --- | --- | --- |
| F-01 scalable native loop | Implemented — QA pending | ext-event backend selected when available; SelectLoop remains bounded fallback |
| F-02 shared-loop coroutine request scopes | Implemented | B0-C contract and B2-B native shared-loop integration certified |
| F-03 worker-wide resource admission/pressure | Implemented — QA pending | bounded request/stream defaults, shared worker byte budget and pressure metrics added |
| F-04 bounded stream-to-response transfer | Deferred from 2.0 core | Existing streaming primitives already permit bounded application pumps; reconsider only with B4 profile evidence |
| F-05 native WebSocket serving | Deferred from 2.0 core | New parser/state/security surface lacks 2.0 evidence; host-native support must be reported truthfully instead |

## Phase 0 contract decisions

The following 2.0 contracts are settled before lifecycle implementation. They are intentionally narrow: existing handler signatures, request objects and unchanged writer methods remain; only ownership signals required to fix the reproduced defects are added.

### Request completion and response ownership

`ResponseWriterInterface` gains:

```php
/** @param callable(ResponseWriterInterface): void $callback */
public function onTerminal(callable $callback): self;
```

A writer invokes terminal observers exactly once when it can no longer accept response work because the response ended successfully or the owned transport/stream became permanently closed. Registering after terminal invokes the observer immediately. `WriteState::REJECTED_LIMIT` or temporary pressure is not terminal. Existing `isEnded()` continues to mean a successful logical response end; terminal notification additionally covers cancellation/closure.

`ApplicationLifecycle::handle()` continues to execute the application handler synchronously, but handler return is no longer request completion. Before dispatch it registers writer-terminal and request-cancellation observers. Finalization is idempotent and occurs exactly once when the writer is terminal or the request context is cancelled. Until then the context remains active and admission remains held. `completeResponse: true` still requests an automatic `end()` after a successful handler return, but it does not bypass terminal accounting.

Finalization order is fixed:

1. classify handler/cancellation state;
2. run request resetters and legacy request cleanup;
3. record request-completed metrics and GC policy;
4. complete/dispose the request context;
5. release request/stream admission.

Transport queues may continue draining after logical writer completion when they own copied output; application/request state must no longer be referenced by that queued output.

### Reset failure and worker retirement

A reset/cleanup failure permanently marks the application lifecycle unhealthy before request ownership is released. Once unhealthy, no new request is admitted. The public runtime application contract gains:

```php
public function healthy(): bool;
public function healthFailure(): ?Throwable;
```

The first isolation failure is retained as the health failure. Synchronous failures may still propagate through `RequestLifecycleException`; failures discovered by a later terminal callback cannot be thrown back through an already-returned `handle()`, so the unhealthy latch is the authoritative signal. Persistent host/native owners must stop admission and retire/recycle the owning worker when `healthy()` becomes false. One-shot FPM/classic execution exits through normal shutdown.

Resetters are request-owned by contract. A resetter that mutates process-global/shared tenant state is not concurrency-safe and must either be migrated to request-owned state or used behind explicit application serialization; Runwire will not silently serialize all 2.0 request handling.

### Coroutine loop ownership

Standalone and attached execution become explicit:

```php
public function run(callable $callback): mixed;
public function runRequest(RequestContext $context, callable $callback): mixed;

/** @param callable(CoroutineScope): mixed $callback */
public function attachRequest(RequestContext $context, callable $callback): Task;
```

`run()` and `runRequest()` are standalone-driving APIs and may own `LoopInterface::run()`. `attachRequest()` never drives the loop; it schedules one request-owned root scope on the runtime's existing scheduler and returns the existing `Task`. Multiple attached request scopes may coexist, inherit the request cancellation/deadline, and close their scope when the returned root task becomes terminal. Native servers and other already-running loop owners must use `attachRequest()`; nested loop driving remains an error.

Task-local inheritance remains snapshot-by-reference for mutable objects; it does not become deep cloning.

### Migration inventory

First-party writer implementors to update together are `Http1ResponseWriter`, `Http2ResponseWriter`, `Http3ResponseWriter`, `CallbackResponseWriter`, and `RoadRunnerResponseWriter`. Lifecycle consumers include `RuntimeApplication`, native HTTP application integration, FPM, FrankenPHP and RoadRunner drivers. Custom 1.x writer implementations must add `onTerminal()`; asynchronous handlers may return before calling `end()` without causing reset/admission release in 2.0.

The response-length rules remain shared across all writers: body-forbidden statuses and HEAD suppression follow `ResponseSemantics`; a declared Content-Length must match the accepted logical body length at `end()`. Callback/RoadRunner writers must adopt the same mismatch behavior as native HTTP writers.
## Phase 0 capability, policy and budget decisions

This section closes B0-D. It distinguishes what Runwire owns and can enforce from what a host runtime may support. The current `RuntimeCapabilities` object still mixes enabled facts with host potential for some adapters; B2-C must make that distinction truthful in code rather than expanding these claims.

### Driver and policy ownership matrix

| Driver | Process/application ownership | Listener, wire and event loop | Admission/deadline ownership | Cleanup, GC and retirement | 2.0 capability rule |
| --- | --- | --- | --- | --- | --- |
| Native prefork | Runwire persistent worker + application | Runwire listener, HTTP/1+2 wire, HTTP/3 only with enabled QUIC; Runwire loop and worker pool | Runwire owns request/stream admission, native connection admission, request deadlines and protocol timeouts | Runwire lifecycle/reset/GC; supervisor owns replacement | Report only detected/enabled native features; scalable-loop capability depends on B3-A backend selection |
| Native portable | Runwire persistent single process + application | Runwire listener/wire/event loop; no managed worker pool | Runwire owns request/stream/connection admission and deadlines | Runwire lifecycle/reset/GC; unsafe reuse stops the process for external replacement | Never advertise worker recycle/reload when no worker pool exists |
| FPM | Host process lifecycle; Runwire application is request-scoped | Host owns listener, HTTP wire and transport deadlines | Runwire can bound normalized request/response size and request lifecycle; host owns connection/transport admission | Runwire request cleanup/GC policy; no Runwire worker replacement | Report request-level capabilities only; do not infer HTTP/2/3, QUIC or host reload features |
| FrankenPHP | Host process; application persistent only in worker mode | Host owns listener/wire/event loop | Runwire owns application lifecycle/deadline after dispatch; host owns connection/protocol admission | Runwire reset/GC; worker retirement only when worker mode exposes it | Current HTTP/2/3, QUIC and WebSocket booleans are host potential, not proof of enabled support; B2-C must correct them |
| RoadRunner | Host worker process + persistent Runwire application | Host owns listener/wire/event loop | Runwire owns application lifecycle/deadline after dispatch; host owns connection/protocol admission | Runwire reset/GC; session stop requests host replacement | Current HTTP/2/3, QUIC and reload booleans must not be treated as enabled without host evidence; B2-C owns correction |
| Swoole/OpenSwoole | Host worker process + persistent Runwire application | Host owns listener/wire/reactor; Runwire coroutine bridge may attach to the host loop | Runwire owns application lifecycle/deadline; host owns connection admission; HTTP/2 is enabled only by Runwire option | Runwire reset/GC; current-worker stop is the retirement primitive | HTTP/1 is supported; HTTP/2 follows configuration; no native Runwire HTTP/3/WebSocket claim in 2.0 |

Policy boundary rules:

- `supports*` must mean usable in the selected, enabled runtime configuration, not merely something a host product can theoretically provide.
- Wire ownership and policy enforcement are separate. Host-owned HTTP/2/3 or TLS cannot be counted as Runwire protocol enforcement.
- Runwire request deadlines begin once Runwire owns the request context. Host connection, handshake and pre-dispatch timeouts remain host policy unless the native driver owns them.
- Request reset, lifecycle metrics and lifecycle GC have one owner: Runwire. Host adapters must not add unconditional per-request `gc_collect_cycles()`; B2-C removes duplicated ownership after verification.
- `maxConcurrentConnections` is directly enforceable only where Runwire owns listener admission. Host adapters must expose the limitation instead of pretending to enforce connection counts.

### Baseline 2.0 resource and work budgets

These are the existing bounded defaults that remain the starting point for 2.0 unless a later batch records measurement-backed changes.

| Owner | Baseline |
| --- | --- |
| Generic native connection | 64 KiB read chunk; 256 KiB read and write work per tick; 1 MiB receive buffer; 1 MiB send buffer |
| HTTP/1 | 64 KiB header block / 100 headers; 16 MiB logical body; 1 MiB pending body; 256 parser steps per turn; 64 KiB response chunk; 10 s header deadline; 30 s body-progress deadline; 1,000 requests per keep-alive connection |
| HTTP/2 | 100 concurrent streams / 10,000 stream creations per connection; 65,535 B pending request body per stream; 1 MiB pending response per stream; 8 MiB aggregate pending response per connection; 1 MiB wire queue; 128 frames per flush; 1,000 control frames/s; 10 s header-block and 60 s stream-idle deadlines |
| HTTP/3 | 100 concurrent request streams / 10,000 request streams per connection; 65,535 B pending body per stream; 1 MiB pending response per stream; 8 MiB aggregate response per connection; 1 MiB blocked-request and QPACK encoder queues; 64 KiB declared control work/tick; 256 reads and 256 KiB inbound bytes/pump; 16 KiB stream reads |
| Coroutine scheduler | 1,024 live tasks; 1,024 ready backlog; 1,024 future/primitive waiters; 128 resumes per loop turn |
| FPM / FrankenPHP / RoadRunner / Swoole adapters | 16 MiB request and 16 MiB response defaults unless explicitly configured lower |
| Logical request payload | 16 MiB default across native protocols and first-party host adapters |

The following worker-wide budgets are **2.0 implementation targets**, not claims about the current code:

- B3-B adds an aggregate queued/buffered-memory admission budget with a **64 MiB default per worker**. It covers Runwire-owned request bodies, pending response queues and protocol queues that can otherwise multiply per-stream limits. Accounting must use actual owned queued bytes, not reserve every per-stream maximum up front.
- B3-B changes the default active request/stream policy from unbounded-by-count to **256 active requests** and **256 active multiplexed streams per worker**, while preserving explicit user configuration and protocol-local lower ceilings.
- B3-A gives the portable `SelectLoop` fallback a conservative **256 concurrently admitted native connections per worker** unless the user explicitly configures a lower value. Higher defaults require a scalable backend proven by the B3-A capacity suite; an accelerated backend may use a larger measured limit without changing the fallback safety cap.
- Per-turn work budgets remain explicit. B3-B must make HTTP/2 frame parsing and HTTP/3 control-stream work consume those budgets instead of materializing unbounded ready work before accounting.
- Generic TCP/Unix `ConnectionLimits` keep optional idle/lifetime timeouts because Runwire cannot impose HTTP policy on generic streams. Managed HTTP keeps finite protocol deadlines.

These values are conservative release defaults, not throughput claims. B4 may lower them for memory safety or raise accelerated-backend concurrency only when production-equivalent measurements and soak evidence justify it.

### Optional feature dispositions

**F-04 — bounded stream-to-response transfer: defer from the 2.0 core API.** Runwire already exposes bounded streaming request bodies, response writers, backpressure/drain notifications and coroutine primitives. A dedicated copy/pump abstraction would duplicate composition logic before B4 demonstrates a throughput or correctness gap. Applications can implement a bounded pump today without buffering a whole payload. Reconsider after B4 profiling identifies repeated boilerplate or a measurable fast-path opportunity; do not add a second response ownership model.

**F-05 — native WebSocket serving: defer from 2.0.** Native WebSocket would add handshake validation, upgrade ownership, frame parsing, fragmentation, masking, control frames, close semantics, message/backpressure ceilings, compression policy and long-lived lifecycle behavior. None of that surface has the regression, interoperability or soak evidence required by this plan. Host products may support WebSocket independently, but B2-C must distinguish host potential from enabled Runwire capability. A later release can add native WebSocket as a dedicated bounded protocol lane rather than coupling it to the 2.0 lifecycle/security closure.

B0-D therefore accepts F-01, F-02 and F-03 for the 2.0 implementation plan, and explicitly defers F-04/F-05 rather than leaving them ambiguous.

## Decision

Runwire has a substantial foundation: bounded protocol parsers and buffers, backpressure, shell-free process execution, privilege-drop ordering, request reset hooks, coroutine limits, and a broad test suite. Nevertheless, additional probes reproduced defects beyond the passing existing tests.

Recommend **Runwire 2.0.0**. The decisive issue is that handler return currently ends application lifecycle accounting even when protocol streams and callbacks remain active. Correcting completion, cancellation, request admission, reset safety and event-loop ownership together needs a consistent contract for custom applications, response writers and host adapters. Plan a documented public-contract migration instead of maintaining a second path with weaker guarantees.

The number of bugs does not itself require a major release. A 1.1 release would be appropriate only if implementation preserved public signatures and observable contracts while delivering the same guarantees. This plan chooses the major option authorized by the user and budgets for intentional contract changes under [Semantic Versioning](https://semver.org/). Exact interface signatures must be settled in phase 0; do not change unrelated APIs simply because a major version permits it. Preserve public named arguments wherever their contract is unchanged. Keep the current PHP requirement unless compatibility evidence establishes a separate reason to change it.

Breaking changes and new feature inclusion are explicitly permitted by the user for 2.0. Compatibility is a migration requirement, not a reason to preserve an unsafe or demonstrably limiting design. New features still need concrete use cases, bounded ownership and verification; permission is not an instruction to add every possible capability.

The implementation must follow `vendor/infocyph/phpforge/resources/engineering-principles.md`, particularly secure defaults, explicit ownership, bounded resources, sustained successful RPM, measurement before optimization, and quality gates without suppression or threshold weakening.

## Evidence and limitations

The review combined the refreshed graphify architecture graph, repository-wide configured quality checks, targeted source inspection across protocol/runtime/process boundaries, and local adversarial probes. It was not an exhaustive manual proof of every source line, a penetration test of a deployed service, or a native-extension audit.

Baseline environment:

- Linux, 64-bit PHP 8.5.4 NTS; Composer 2.10.3.
- PCNTL, POSIX and OpenSSL available.
- Swoole, OpenSwoole, QUIC, event and Xdebug extensions absent.
- CLI OPcache disabled; this host configuration cannot establish production performance.

Executed:

- `composer ic:doctor`: healthy; configured PHP matrix 8.4/8.5.
- `composer ic:list-config` and `composer ic:active-config`: reviewed installed PHPForge configuration.
- `IC_TEST_CONCURRENCY=4 composer ic:release:guard`: exit 0.
- Existing tests: **415 passed, 2,564 assertions**.
- Composer audit: **0 advisories**, one non-blocking abandoned development dependency, `doctrine/annotations`.
- Syntax: 442 PHP files; reference check: 342 symbols, 7,423 references; configured formatting, comments, architecture, PHPStan, Psalm and Rector checks passed.
- Duplicate detector reported 65 clone groups / 3,049 duplicated lines / 7.94%, while its configured gate passed. These remain review findings, not evidence of a clean duplication report.
- Additional bounded local probes reproduced 14 observations: RW-01–09 and RW-17–21. Their evidence ranges from real local socket behavior to isolated adapter/session behavior; they are not 14 demonstrated security exploits. RW-10–16 and RW-22 are source-level findings or investigation work.

The release-guard log and probe scripts were generated under `/tmp/runwire-*`; temporary files are session evidence, not durable release artifacts. Turn the reproductions below into committed regression tests during remediation.

`composer ic:bench:quick` was requested but **not executed**: automatic approval review could not complete because its service usage limit was reached. No throughput improvement or peer ranking is claimed. Real PHP 8.4, Windows/macOS portability, RoadRunner, FrankenPHP, Swoole/OpenSwoole, QUIC interoperability, long-duration soak and final-revision CI were not executed in this review.

The previously present `docs/plans/runwire-1.0-foundation-3-launch-plan.md` was deleted by a concurrent workspace change during review. That deletion is outside this audit's changes and must not be restored automatically. Review existing documentation links to that path when consolidating release documentation.

## Review scope and threat boundaries

The source inventory contains **330 tracked PHP files**. Automated checks cover their configured repository scope; manual review concentrates on trust boundaries, state transitions, resource ownership and relevant callers/tests. The graph is a navigation aid, not evidence that a path is secure.

| Subsystem | Manual review focus and evidence | Remaining release evidence |
| --- | --- | --- |
| HTTP/1, HTTP/2, HTTP/3 and compression | Request/response framing, parser scheduling, flow control, HPACK/QPACK limits, stream lifecycle, callback errors; local HTTP/1/2 socket and HTTP/3 session probes. | Differential proxy tests, bounded fuzz/property corpus, real QUIC and slow-peer soak. |
| TCP, Unix, UDP, TLS and generic framing | Accept/read/write batching, buffer ceilings, close/pause, TLS configuration, Unix/control ownership; descriptor, socket replacement and UDP probes. | Real mTLS handshakes, race tests, alternate loop and OS coverage. |
| Loop, timers, cancellation and coroutines | Watcher/timer ownership, cancellation, scheduler driving, bounded queues/tasks, task-local inheritance; timer and nested-loop integration probes. | Shared-loop multi-request integration, cancellation races and sustained concurrency. |
| Runtime/application lifecycle and host adapters | Admission, completion, resetters, failure containment, response framing, host capabilities, GC/recycle; H2 lifecycle and callback-writer probes. | Real RoadRunner, FrankenPHP, Swoole/OpenSwoole and FPM acceptance. |
| Processes and supervision | Shell-free argv, I/O bounds, cancellation, reaping, signals, privilege-drop ordering, restart/reload/drain and worker retirement paths. | Descendants, repeated failure/reload soak and advertised OS behavior. |
| Metrics, resources, public options and control | Outcome timing, cardinality/resource ceilings, capacity detection, policy ownership, bounded control requests and local authorization. | Accurate terminal outcomes, nested-cgroup/deployment limits and aggregate-budget calibration. |
| Dependencies, CI, docs and benchmark tooling | Composer audit, quality gates, workflow provenance, advertised capability/portability and performance evidence. | Immutable CI inputs, clean consumer installs, final-candidate matrix and real-server baselines. |

Threat model: remote peers can connect, withhold bytes, fragment/coalesce traffic, send malformed frames, multiplex streams and read slowly. Persistent applications can throw or fail cleanup while handling different tenants. Local users matter where they can access socket parent directories. Host servers and extensions are separate trusted parser/transport boundaries that need integration verification. Runwire is not a sandbox for malicious PHP handlers or an untrusted OS administrator.

Retain existing strengths: bounded header/body/frame/compression limits, transport backpressure, shell-free process execution, finite control messages, restrictive default control permissions, privilege drop before bootstrap, task limits and restart backoff. A local socket ID is not a secret, an application callback is not a safe exception-message source, and a per-stream limit is not a worker-wide memory limit.

## Confirmed findings

Severity is a remediation priority based on observed behavior and stated preconditions, not an assigned CVSS score or public vulnerability advisory.

### RW-01 — High: HTTP/1 parser loses its continuation at the work budget

Owner: `src/Http/Http1/Http1Connection.php`, `pump()` and `schedulePump()`.

`pump()` tries to schedule continuation while `$pumping` is true; `schedulePump()` refuses in that state. Data already buffered can remain unprocessed until unrelated socket activity or timeout.

Reproduction: write a complete chunked POST into a socket pair in one write, with 100 one-byte chunks and the final zero chunk. Keep the default 256 parser-step budget; shorten only the body timeout to 40 ms. Consume body data and respond from `onEnd()`. The handler starts, the body never ends, and the server returns 408. Separately, a complete GET with `maxParserStepsPerTick: 1` times out before dispatch.

Fix in the existing parser: record the need for another turn and enqueue it after clearing the re-entry guard. Ensure at most one deferred continuation, cancel/no-op it after closure, and preserve finite work per turn. Do not remove the budget or increase it to hide the defect.

Acceptance: complete buffered chunked uploads, long pipelines and tiny parser budgets progress without additional client bytes; another connection and a timer remain responsive; close, EOF and body-backpressure relief cannot duplicate dispatch or spin.

### RW-02 — High: empty Transfer-Encoding bypasses framing rejection

Owner: `src/Http/Http1/Internal/RequestHeadValidator.php`, `validate()` and `tokens()`.

The validator tests the normalized token list rather than field presence. Empty or comma-only `Transfer-Encoding` becomes an empty list and is treated as absent, including alongside `Content-Length`.

Reproduction: validate `Host: x`, `Content-Length: 5`, and either `Transfer-Encoding:` or `Transfer-Encoding: ,`. Both are accepted with content length 5. An ordinary `chunked` plus Content-Length is correctly rejected.

This is a confirmed framing-validation gap with potential request-smuggling implications when another HTTP hop interprets it differently. A working multi-hop exploit was not demonstrated. RFC 9112 section 6.3 treats messages containing both fields as suspicious and requires connection closure even when a server elects to process them: <https://www.rfc-editor.org/rfc/rfc9112.html#section-6.3>.

Fix: distinguish field absence from an invalid/empty coding list; preserve Runwire's strict rejection policy for TE plus Content-Length before normalization. Validate the transfer-coding grammar without accidentally rejecting permitted list whitespace/empty-element handling around a valid coding.

Acceptance: raw-wire tests for empty, whitespace, comma-only, repeated and mixed-case fields, duplicate lengths, oversized numbers, fragmented input and pipelined follow-up requests. Rejected messages must never dispatch or leave a reusable connection. Add differential tests through supported reverse proxies before making an exploitability claim.

### RW-03 — High for directly exposed native servers: silent connections have no default expiry

Owners: `src/Server.php`, `src/Network/ConnectionLimits.php`, `src/Http/Http1/Http1Connection.php`.

Default connection idle/lifetime timeouts are null. HTTP/1 arms its header timer only after input becomes available. A client that connects and sends no bytes therefore never starts that deadline. Idle keep-alive periods need explicit coverage too.

Reproduction: attach HTTP/1 with a 40 ms header timeout, send nothing and run for 120 ms. The connection remains open. Source inspection establishes that neither default transport timer expires it later.

Fix: give managed HTTP listeners a finite first-byte and keep-alive idle policy; separate header completion, body progress, total request time and slow-output deadlines. Avoid silently applying HTTP-specific lifetime choices to generic TCP/Unix applications. Establish safe finite HTTP defaults and document the finite defaults, streaming overrides and changed timeout behavior in the 2.0 migration guide.

Acceptance: fill a deliberately small listener with silent clients and show capacity returns on deadline; test TLS completion followed by silence, between-request idle, slow-drip headers/bodies, slow readers and legitimate streaming. Confirm each supported host's ownership of these deadlines.

### RW-04 — High: SelectLoop can stop all socket progress at the descriptor ceiling

Owners: `src/Loop/SelectLoop.php`, `src/Network/ListenerOptions.php`, native runtime construction.

Native managed workers construct `SelectLoop`; default listener and worker limits are 10,000. On the reviewed PHP build, `stream_select()` rejects descriptors at or above its compiled FD_SETSIZE boundary. `poll()` retries permanent select errors after a short sleep, leaving socket watchers without progress.

Reproduction: open 1,050 `/dev/null` handles in an isolated probe process, then watch a socket containing readable data. PHP reports FD_SETSIZE 1024 and a descriptor at least 1054; the callback never runs. The probe was bounded by a 30 ms timer. The relevant limit is the OS descriptor number, not simply watcher count or PHP resource ID.

Correction: distinguish interrupted/transient select failures from permanent capacity failures, emit bounded diagnostics, fail safely, and establish conservative admission/headroom guidance verified on the target platform. Merely lowering a connection-count constant does not protect against descriptors opened elsewhere in the process.

Capacity work in 2.0: make the managed runtime capable of selecting/injecting a scalable loop through the existing `LoopInterface`, with a production-tested accelerated adapter and explicit capability reporting. Installing `ext-event` alone currently does not change the managed worker's `new SelectLoop()` construction.

Acceptance: isolated descriptor-pressure tests below/above the actual platform boundary, including pre-opened files; no endless warning loop or silent loss of all I/O. Accelerated-loop tests must demonstrate progress beyond 1,024 descriptors, bounded event batches, timers, cancellation, shutdown and equivalent behavior.

### RW-05 — High, application-dependent confidentiality: HTTP/2 sends callback exception text to the peer

Owners: `src/Http/Http2/Http2Connection.php`, `pump()` and `failConnection()`; streaming callback invocation.

The generic Throwable catch sends the exception message through GOAWAY debug data, truncated to 128 bytes. Direct handler failures are sanitized elsewhere, but a later body callback takes this path.

Reproduction: register a body `onData()` callback that throws `RuntimeException('AUDIT_SECRET_database_password')`, then send an HTTP/2 DATA frame. The marker is present in the peer's received bytes.

Fix: use fixed public error text for unexpected/application failures; retain detailed diagnostics only in an appropriate local reporting channel. Audit HTTP/3 close reasons, host error responses and all asynchronous callbacks for the same boundary. A length limit is not redaction.

Acceptance: unique sentinel secrets in exceptions from handler, body/data/end/cancel, writer/drain, resetter and transport callbacks never appear in response bytes, HTTP/2 debug data or HTTP/3 close reasons. Expected protocol errors remain distinguishable through stable codes.

### RW-06 — High, application-dependent isolation: failed reset does not make the worker unhealthy

Owners: `src/Runtime/ApplicationLifecycle.php` and `src/Http/Http2/Internal/RequestStreamProcessor.php`.

The lifecycle reports resetter failure via `RequestLifecycleException` but does not latch an unhealthy/draining state. HTTP/2 catches handler exceptions at stream scope and continues serving requests with the same application. If cleanup failed to remove user/tenant state, later requests can observe contaminated state.

Reproduction: install a resetter that always throws and send two HTTP/2 requests on streams 1 and 3. The application handler is invoked twice despite the first cleanup failure. This proves continued reuse; an actual cross-tenant data leak depends on application state and was not demonstrated.

Fix: latch unsafe cleanup failure in the lifecycle, reject further admission and propagate retirement to the owning worker/host. Distinguish ordinary request errors from loss of isolation. Coordinate existing in-flight requests and bounded shutdown. Portable mode must terminate safely for external replacement; prefork and host modes must request their supported replacement mechanism.

Acceptance: a resetter leaves an explicit tenant sentinel and throws; subsequent requests cannot reach that state. Cover HTTP/1, multiplexed HTTP/2/3, real host adapters, concurrent requests, failed retirement, metrics and restart classification. Never report a success-only request outcome when required cleanup failed.

### RW-09 — High: streaming requests outlive admission, context and cleanup

Owners: `src/Runtime/ApplicationLifecycle.php`, `src/RequestContext.php`, protocol stream dispatch and `src/Http/ResponseWriterInterface.php`.

`handleAdmitted()` completes the request and runs cleanup on handler return. Callback-style handlers can return while their bodies and responses remain open. `RequestContext::complete()` clears attributes and disposes cancellation; admission is released and completion metrics advance before the stream finishes.

Reproduction: configure `maxActiveRequests: 1` and `maxStreamsPerWorker: 1`; send unfinished HTTP/2 requests on streams 1 and 3. Both handlers run, both protocol streams remain active, and both contexts are already completed. A later DATA callback for stream 1 observes `completed() === true` and its previously assigned tenant attribute as null. This demonstrates admission and lifetime mismatch, not an observed cross-tenant disclosure.

Fix: define one exactly-once terminal lifecycle spanning deferred body/response work and owned tasks. Keep admission, deadlines and cancellation alive until that terminal point, then release/reset. Define safe disposal when a response ends before the request body is consumed. Resetting shared application/global state while another request is active is not safe isolation: either use request-owned state, a proven reset barrier, or explicitly serialize admission for that application. Integrate unsafe reset retirement from RW-06.

Acceptance: delayed body and response, early response, disconnect, deadline, write failure, task cancellation and concurrent tenant tests; active-request metrics track actual owned work. Define response acceptance/flush separately from delivery to a client. Neither `end()` acceptance nor handler return proves that a remote client received a successful response.

### RW-17 — P1 reliability: stopped timer batches lose pending timers

Owners: `src/Loop/Internal/TimerQueue.php::takeDue()` and `src/Loop/SelectLoop.php::runDueTimers()`.

The queue removes all due timers from its heap before callbacks run. If one callback stops the loop, later entries in that batch can remain in the active map without a heap entry. The same ownership concern applies when a callback throws.

Reproduction: schedule two zero-delay timers; the first records an event and stops the loop. Tick again. Only the first event occurs, while diagnostics still report one active timer.

Fix in the existing queue/loop owner: consume due timers incrementally or safely preserve every unprocessed entry on early exit. Test stop/resume, exceptions, cancellation, repeating timers, timer references and disposal; no lost deadlines or busy loop from stranded active entries.

### RW-18 — P1 contract correctness: host response writer accepts inconsistent framing

Owners: `src/Http/Internal/CallbackResponseWriter.php`, `src/Runtime/Host/RoadRunnerResponseWriter.php` and shared response validation.

Reproduction: start a callback response with status 200 and `Content-Length: 1`, then end with `AB`. The writer reports acceptance and passes the declared length and two bytes to the host callbacks. Source review found the same missing length accounting in the RoadRunner writer, which was not exercised against its real host.

This is an adapter contract defect. A downstream host may repair or reject the response; response smuggling on an actual deployment was not demonstrated. Centralize protocol-appropriate validation in the existing response owners: declared lengths, body-forbidden statuses and HEAD semantics, duplicate framing fields, connection-specific headers and write rejection/partial acceptance. Keep host transport responsibilities explicit.

Acceptance: the same response-contract corpus across native writers, callback writer and real hosts; never return success for a knowingly inconsistent response. Test failure before/after headers, retries after backpressure and end/cancel exactly once.

### RW-19 — High correctness: HTTP/3 body handling depends on read boundaries

Owners: `src/Http/Http3/Http3Session.php`, `src/Http/Http3/Internal/RequestStream.php` and their frame/body processing.

Component reproduction: with an 8-byte pending-body cap and 2/4-byte low/high watermarks, send valid request HEADERS plus two six-byte DATA frames. An application drains body data in `onData()`. A single push containing all three frames fails with a body-capacity exception before dispatch; three separate pushes dispatch once and consume all 12 bytes. Separately, default limits reject a single 65,536-byte DATA payload even though the configured frame ceiling permits it, because pending-body capacity is 65,535 bytes.

This proves segmentation-dependent session behavior, not a reproduced native QUIC failure. Dispatch complete headers before later DATA processing and incrementally deliver/pause/resume bodies within bounded memory, including frames larger than the pending-body buffer. Do not hide the problem by simply enlarging buffers or parsing an entire upload eagerly. Follow HTTP/3 message/stream semantics in [RFC 9114](https://www.rfc-editor.org/rfc/rfc9114.html#section-4.1).

Acceptance: equivalent requests under every relevant fragmentation/coalescing boundary; large permitted DATA, stalled/resumed consumers, FIN, reset and QPACK blocking; no premature dispatch, loss, duplicate bytes or unbounded buffering. Repeat through the real transport.

### RW-20 — P1 architecture/performance: coroutine waits can hold up the native server loop

Owners: `src/Coroutine/CoroutineRuntime.php`, `src/Coroutine/Internal/FiberScheduler.php`, `src/Runtime/CoroutineRequestHandler.php` and native worker loop construction.

The documented default coroutine runtime creates a separate SelectLoop and synchronously drives it until the request scope settles. It overlaps tasks within that request, but does not return control to the outer server loop during a wait. Supplying the already-running server loop cannot solve this while `execute()` always starts `run()` again.

Bounded reproduction: an outer-loop callback invokes the default coroutine runtime and sleeps for 20 ms in a scope. An already-due outer timer runs only after the coroutine finishes and the handler returns. This establishes event order; it is not a throughput benchmark or a finding against every host coroutine implementation.

Fix: establish one event-loop owner and attach request-scoped coroutine work to that running scheduler; retain explicit standalone driving where needed. Support multiple request scopes without nesting event loops or sharing mutable request state. Decide the integration contract before introducing another scheduler abstraction.

Acceptance: two native connections with waiting coroutine handlers progress alongside timers and other I/O; shared-scheduler task budgets, cancellation, fairness, shutdown and per-request locals remain correct. Verify real Swoole/OpenSwoole host ownership separately.

### RW-21 — P1 reliability: closing a UDP listener in its callback crashes the receive batch

Owner: `src/Network/DatagramListener.php::handleReadable()`; inspect analogous TCP/Unix accept loops.

Reproduction: bind a local UDP listener, send one datagram and call `close()` inside the receive callback. The next receive iteration throws `TypeError: stream_socket_recvfrom(): Argument #1 ($socket) must be an open stream resource`. The callback is allowed to close the listener, but the batch retains the closed resource.

Fix: after callbacks, re-check closure, pause state and current resource ownership before another receive/accept operation. The UDP path is reproduced; analogous TCP/Unix behavior is source-level follow-up. Test close, pause/resume, throwing callbacks, queued datagrams/connections and watcher cleanup with batch sizes greater than one.

## Configuration findings and remaining source investigation

RW-07/08 include local configuration/socket reproductions. The remaining items are source findings and bounded investigation tasks, not demonstrated remote exploits. P1 items must be resolved before release; P2 items need a documented disposition and must be fixed when investigation establishes a correctness, security or stability defect.

| ID | Priority | Evidence and required work |
| --- | --- | --- |
| RW-07 | P1 | `TlsOptions::context()` overwrites `extraContext` with `verify_peer: false` and `verify_peer_name: false`. A configuration-only probe confirms explicitly supplied true values become false; no TLS handshake was run. Server-side client verification is legitimately optional, but an explicit request for it must not silently disappear. Define supported mTLS policy or reject unsupported verification settings; test missing/untrusted/trusted client certificates in real TLS and QUIC where supported. Mark passphrase input sensitive and prevent diagnostic leakage. |
| RW-08 | P1 | `UnixListener::preparePath()` treats any existing socket as stale when removal is enabled; ControlServer enables this. A local probe bound a second listener at the same path while the first remained open: the inode was replaced. It does not establish whether another server owns a live endpoint. Socket creation precedes chmod; no hostile local race was demonstrated. Validate private parent-directory ownership/permissions, safe creation and live/stale/replacement handling; test two live instances and local races. The runtime ID is returned by status/error responses and must be described as instance identification, not an authentication secret. Filesystem access is the current control authorization boundary. |
| RW-10 | P1 | HTTP/2 `FrameParser::push()` materializes every available frame before control-frame budgets execute; small/unknown/PRIORITY frames and new-stream churn need work-budget coverage beyond byte limits. Add incremental processing and per-turn/global fairness where measurements justify it; preserve HPACK synchronization for refused streams. Also verify that connection write budgets apply per event-loop turn rather than resetting on each write call. Existing control/continuation limits are useful and must remain active. |
| RW-11 | P1 | HTTP/3 exposes `maxControlBytesPerTick`, but source search found no consumer beyond declaration/validation. There are transport read/pump limits, so this is an unenforced advertised policy, not proof of unbounded total work. Implement precise shared tick accounting or correct the contract. Establish request-header/body/QPACK-blocked deadlines; transport idle timeout alone does not prove stream progress when peers keep a connection active. Verify native resource cleanup after unexpected exceptions. |
| RW-12 | P1 | Failure containment differs: HTTP/1 rethrows handler failures into the native loop; HTTP/2 handles direct failures at stream scope. Define which failures terminate a stream, connection or worker, while always retiring unsafe reset failures. Test that one ordinary application failure does not unnecessarily destroy unrelated healthy work. |
| RW-13 | P2 | `ProcessTerminator` signals the direct child; `ProcessHandle` retains detached running handles in a static list reaped on subsequent runs. Define process-tree ownership and bounded detached cleanup, then test descendants retaining pipes, cancellation, repeated failed starts and repeated forced termination. ProcessRunner is synchronous and must not be advertised as nonblocking merely because it uses nonblocking pipes. Verify absolute-path handling and null-device assumptions on advertised platforms. Preserve argv execution, environment/cwd policy and finite I/O/termination ceilings. |
| RW-14 | P2 | RoadRunner and FrankenPHP call `gc_collect_cycles()` after every request in addition to lifecycle GC policy. Profile real hosts with representative allocation pressure before consolidating GC ownership. Verify tail latency and memory plateau, not just operation timing. |
| RW-15 | P2 | Review the 65 reported clone groups for cohesive shared owners, particularly duplicated security validation. Centralize valid duplicated logic and update all callers without proliferating wrappers or erasing distinct protocol semantics. Do not change detector thresholds or baselines to clear the report. |
| RW-16 | P2 | Preserve the repository's existing version/tag refs for third-party actions, reusable workflows and development tooling. Use automated update cadence and required CI gates rather than converting those refs to commit SHAs. Record extension/client/build versions and production-install provenance where release evidence requires it. Resolve abandoned transitive packages through their owning dependency where possible. |
| RW-22 | P1 | `RuntimeCapabilityResolver` advertises several host features independently of installed host configuration/build, while policy enforcement can belong only to the native listener. Distinguish potential host support, enabled support, Runwire-accessible operations and ownership. Test each advertised operation; unsupported enforced policies must fail clearly or identify a verified host configuration requirement. Include first-byte/body/output deadlines, connection admission, graceful reload, recycle and coroutine integration. No real-host capability matrix was executed in this review. |

## 2.0 public contracts and migration

Prefer changes to existing cohesive owners and interfaces. This table specifies required behavior, not speculative class names or a second framework inside Runwire.

| Contract | Required 2.0 behavior | Consumer migration |
| --- | --- | --- |
| Application/request completion | Exactly one observable terminal success, failure or cancellation after owned asynchronous work settles; admission and cleanup use this boundary. | Update custom `RuntimeApplicationInterface` integrations and deferred handlers to the finalized completion contract. Synchronous handlers retain a simple path. |
| Response writers | Consistent accepted/rejected write semantics, validated response framing, terminal/error notification, drain behavior and ownership of queued output. | Update custom `ResponseWriterInterface` implementations; demonstrate partial/failed writes and asynchronous end rather than assuming handler return finishes output. |
| Reset/isolation and worker health | Request-owned cleanup plus a safe policy for shared state; irreversible unhealthy state after failed isolation cleanup; owning runtime retires it. | Classify resetters as request-local or shared; migrate singleton/global tenant state, or explicitly serialize applications that cannot isolate concurrent requests. |
| Coroutine and loop ownership | Standalone scope driving versus attachment to an already-running runtime is explicit; multiple request scopes share bounded scheduling safely. | Replace independent per-request loop driving in native HTTP integrations; validate supported host coroutine mode. Document that task-local value snapshots do not deep-clone mutable objects. |
| Resource/deadline policies | Finite managed HTTP defaults, enforceable per-turn work and aggregate worker limits, explicit host policy delegation. | Review timeout and capacity settings for uploads, SSE/streaming and long-lived connections. Do not silently inherit unlimited behavior. |
| TLS, Unix/control and capabilities | Explicit verification requests are honored or rejected; live sockets are not replaced; advertised usable features match runtime configuration. | Supply supported client-verification settings and safe socket directories; adapt capability checks and deployment configuration to actual enabled support. |
| Metrics and errors | Separate handler execution, active owned requests, terminal outcomes and host/transport acceptance; confidential details stay local. | Update dashboards and hooks that treated handler return as successful request completion. Client-observed benchmark success remains separately validated. |

Phase 0 must enumerate actual signature, constructor, named-argument and behavior changes in a migration guide with before/after examples. Update all first-party implementations and relevant Foundation/application consumers together. Do not add a compatibility path that silently restores unsafe completion. Preserve unchanged public contracts and avoid unrelated namespace changes, package splits or PHP minimum increases.

## Feature roadmap beyond defect correction

The first three additions belong in the 2.0 core plan because they address observed architecture/capacity constraints. Additional candidates below may join 2.0 when their evidence and maintenance cost justify them. This is a recommendation and acceptance plan; none of these features was implemented during the audit.

| Feature | Scope and rationale | Security/performance acceptance | Release decision |
| --- | --- | --- | --- |
| F-01: supported scalable native loop selection | Provide a tested accelerated backend selected/injected through the existing loop abstraction and worker construction. Expose the selected backend and capacity limitations. | More than 1,024 actual descriptors on a supported backend; bounded timers/I/O batches, cancellation and drain; controlled failure on fallback; sustained RPM and loop-lag comparison. | Include in phase 3. Choose backend after platform/dependency evaluation. |
| F-02: multiple coroutine request scopes on one running loop | Native servers can overlap waiting requests using one owned scheduler, with per-request task groups and cancellation. Preserve standalone coroutine use. | Isolation and cancellation under concurrent tenants; enforce task/waiter/backlog budgets; improve I/O-bound successful RPM without starving ordinary callbacks. | Include in phase 2; public API changes are permitted. |
| F-03: worker-wide resource admission and pressure reporting | Extend existing admission, metrics and supervision owners with aggregate connection/stream/task/queued-byte limits and observable rejection reasons. Retain existing overload responses/readiness/drain rather than building parallel mechanisms. | Enforce limits before expensive allocation where possible; stable queues/RSS and recovery after saturation; bounded metric labels and no per-request mandatory logging; calibrate polling cost. | Include in phase 3. Static explicit limits first; adaptive control requires separate evidence. |
| F-04: bounded stream-to-response transfer | Assess a cancellable transfer operation for already-authorized stream resources, including large files and downloads, integrated with writer backpressure. Avoid requiring applications to build the same error-prone pump repeatedly. | No whole-file buffering, event-loop monopolization or leaked handles; partial writes, disconnect, ownership and length semantics tested. Compare against existing chunked writes under TLS and plain transport; do not claim zero-copy without platform evidence. | Conditional 2.0 candidate after phase 2; retain only if it materially simplifies correct use or improves measured throughput. |
| F-05: native WebSocket serving | A concrete capability expansion beyond native HTTP/TCP: start with HTTP/1 upgrade and a bounded session/message API that reuses transport, cancellation, admission and lifecycle ownership. | Explicit application authorization/origin policy, masking/UTF-8/frame validation, message and fragmented-message ceilings, heartbeat/close deadlines and slow-reader backpressure; interoperability/fuzz/soak plus HTTP workload isolation. | Conditional 2.0 candidate after core lifecycle fixes. No automatic HTTP/2/3 WebSocket or compression claim. |

For F-05, follow the handshake, framing and security requirements of [RFC 6455](https://www.rfc-editor.org/rfc/rfc6455.html). Origin checks complement application authentication; they do not authenticate non-browser clients. Keep compression disabled initially. Any later compression support needs its own negotiation, resource and confidentiality review against [RFC 7692](https://www.rfc-editor.org/rfc/rfc7692.html).

Each conditional feature gets a phase-0 decision record: actual consumer/use case, existing owner, public contract, supported drivers/platforms, resource ceilings, abuse corpus, before/after workload measurements, dependency cost and migration/maintenance impact. A capability expansion need not speed up an unrelated workload, but it must meet its own performance budget and avoid an unjustified cost when unused. If evidence does not justify inclusion, explicitly defer it to a later minor release; do not leave a half-supported feature advertised in 2.0.

Keep framework adapters, outbound database/HTTP client pools, a distributed job system and automatic global monkey-patching outside this release unless a concrete consumer requirement justifies their separate design. Runwire can integrate with such systems without reimplementing them. Optimize the highest measured sustainable successful RPM under security, correctness, latency and resource constraints; do not promise an absolute maximum across every workload.

## Implementation phases for the single 2.0.0 target

### Phase 0 — reproducible regressions and contract decisions

1. Commit deterministic reproductions for RW-01–09 and RW-17–21 in the existing relevant suites. Isolate descriptor and malformed-input probes in bounded subprocesses; preserve current passing coverage.
2. Set the completion/cancellation/retirement state transitions, response-write contract, concurrent-reset policy and coroutine loop ownership. Inventory all interface implementors, adapters, tests and consumer examples before changing signatures.
3. Record a per-driver ownership table for every security/resource policy and actual enabled capability (RW-22). Unsupported behavior must be explicit.
4. Establish production-equivalent baseline measurements before hot-path changes. Calculate aggregate memory bounds from connection buffers, active streams, parser/compression state, queued responses, tasks and admission limits. Per-object ceilings multiplied by allowed concurrency must fit the worker/deployment budget with headroom.
5. Decide conditional F-04/F-05 inclusion using the feature criteria above; map any accepted feature into phases 2/3 and its release acceptance lane.
6. Define measurable target-workload latency/error/resource budgets and the precise supported PHP, OS, host and transport matrix. Preserve current claims until they are verified or explicitly revised as part of the major migration.

Exit: committed failing regressions, concrete contract/migration decisions and recorded baseline/configuration evidence. Security corrections may proceed while a benchmark environment is prepared; final performance certification remains required.

### Phase 1 — protocol, transport and loop corrections

1. Fix HTTP/1 continuation, framing and finite managed HTTP idle policy (RW-01–03); retain bounded parsing and correct streaming behavior.
2. Correct permanent select failure handling and stranded timers (RW-04/17); repair callback closure/pause handling (RW-21).
3. Redact asynchronous failure text, honor explicit TLS policy and prevent live Unix socket replacement (RW-05/07/08).
4. Repair HTTP/3 incremental body delivery and host response validation (RW-19/18). Exercise fragmented and coalesced paths before optimization.

Exit: relevant adversarial regressions pass with unchanged safety budgets. No known high-priority protocol/transport defect remains on a supported path.

### Phase 2 — completion, isolation and consistent runtime ownership

1. Implement the phase-0 lifecycle contract across HTTP/1/2/3, application/context, response writers and every host adapter (RW-06/09/12/18).
2. Make unsafe reset failure stop admission and retire the correct worker; coordinate already-active requests without allowing shared-state leakage. Exercise standalone/portable, prefork and host replacement paths.
3. Attach coroutine scopes to the owning loop and prove concurrent native requests make progress (RW-20). Align cancellation, deadlines, active counts and request metrics with actual owned work.
4. Complete truthful capability reporting and explicit policy delegation (RW-22). Resolve shared GC ownership only with representative host measurements (RW-14).

Exit: all first-party implementors and consumer migrations pass the common contract suite and isolation tests; no legacy path bypasses terminal accounting or failed-reset retirement.

### Phase 3 — bounded capacity and operational hardening

1. Integrate/select a scalable production loop through `LoopInterface` in the existing worker owners (RW-04); choose an optional adapter/dependency based on verified platform support and measured capacity, not fashion. Keep SelectLoop as a bounded portable fallback with explicit failure behavior.
2. Finish HTTP/2/3 work accounting, stream progress deadlines, compression synchronization under refusal, backpressure and aggregate admission (RW-10/11/19). Ensure controls apply across a whole turn/worker, not only per method call.
3. Close process-tree/detached cleanup and platform contracts (RW-13), validate nested-cgroup/resource detection and test overload/reload recovery.
4. Implement any accepted F-04/F-05 feature in its existing transport/lifecycle owners with the agreed contract and bounded abuse tests. Keep declined candidates out of capability claims.
5. Resolve valid duplicated security/validation owners and measured maintenance issues (RW-15), without unneeded general abstractions. Pin reviewed executable CI inputs and resolve dependency provenance/abandonment (RW-16).

Exit: verified bounded resources and fair progress at supported capacity; no P1 issue open. Every P2 finding has a correction or evidence-based disposition. Optional cosmetic cleanup and speculative features are outside the release blockers.

### Phase 4 — sustained-performance and release certification

Use the existing benchmark owners and PHPForge tooling first. Extend the current microbenchmark/comparison tooling with real-server workloads rather than creating a competing harness without need.

- Establish a baseline before hot-path changes using production-equivalent PHP, Composer, extensions and OPcache; measure cold starts separately.
- Separate HTTP/1.1, HTTP/2 and HTTP/3, with explicit TLS, worker count, connection reuse and concurrency.
- Cover minimal response, JSON request/response, streaming upload/download, slow readers, multiplexing, cancellation, overload recovery, coroutine I/O and worker reload/recycle. Add real Foundation/application routes for full-stack claims.
- Sweep concurrency to saturation and run at least five repeated steady-state trials. Initial plan: 30-second warmup plus 180-second measurement per trial, followed by at least 30 minutes of mixed-load soak; extend durations until queue and memory behavior is stable. These are proposed test settings, not measured results.
- Count only complete correct responses. Primary metric: median sustained successful RPM (`successful RPS × 60`), with p50/p95/p99, errors/timeouts, validation failures, CPU, steady/peak RSS, PHP allocation, descriptors, active connections/streams/tasks, queue depth and event-loop lag.
- Verify load-generator headroom and record variance. Establish concrete workload latency/error/resource budgets before optimization. Use matching stable-environment metadata for PHPForge regression comparison; do not enforce small timing budgets on noisy shared runners.
- A provisional 5% RPM regression budget is suitable only after baseline variance is shown to be smaller; security/correctness fixes remain mandatory, with any measured cost investigated and documented rather than hidden by weakening validation.
- Extend existing benchmark result validation to require completed/successful/error/timeout counts, correctness checks and relevant host/TLS/OPcache/build metadata; accepting a numeric RPM field alone is not evidence of successful throughput.
- Run equivalent real peer deployments on the same hardware/configuration only when making comparative claims. Current schema placeholders and host-adapter microbenchmarks are not peer measurements.

Exit: correctness and isolation stay intact under representative load; latency, errors and resources meet the phase-0 budgets; sustained successful RPM and variance are documented. A justified security cost must be visible, never hidden by disabling validation. No blanket equivalence claim to Octane, Symfony Runtime or Workerman: compare the actual host integration and workload.

### Phase 5 — beta, migration and final candidate

1. Publish reviewable beta/RC artifacts only through the project's authorized release process; this audit does not tag, publish or send disclosures. Validate real consumer migrations, production `--no-dev` installs and packaged contents.
2. Update API examples, deployment/security guidance, capability tables and benchmark documentation. Repair links in README and architecture/getting-started/coroutines/benchmarks/deployment docs to the removed historical plan, without restoring that deletion automatically.
3. Close every confirmed finding with regression evidence, affected versions/configurations and any remaining mitigations. Follow `SECURITY.md` for disclosure decisions.
4. Run all required gates on the exact final candidate; record immutable commit and artifacts. Target the final **2.0.0** release only when those gates pass. Do not represent this planning audit as release readiness.

## Required regression and acceptance matrix

| Surface | Required evidence |
| --- | --- |
| HTTP/1 | Framing ambiguity corpus; fragmentation/coalescing invariance; parser-budget continuation; pipelining; EOF; first-byte/header/body/output deadlines; bounded memory and backpressure. |
| HTTP/2 | HPACK limits; CONTINUATION/PING/SETTINGS/RST/PRIORITY/unknown-frame and stream churn; flow-control correctness; error redaction; cleanup failure retirement; fairness between streams/connections. |
| HTTP/3 | QPACK blocked/unblocked/cancelled streams; control-byte accounting; stream deadlines; native transport cleanup; fragmentation/coalescing invariance and permitted large DATA delivery; real aioquic and ngtcp2/nghttp3 interoperability and soak/drain. |
| Persistent lifecycle | Alternating tenant/user sentinel checks on success, handler error, resetter error, cancellation, late streaming callbacks and concurrent requests; admission held until terminal work; no admission after unsafe reset; shared resetters cannot mutate another active request. |
| Coroutines | Single loop ownership, multi-request progress, timer stop/resume/throw; cancellation versus readiness races; bounded tasks/waiters/backlog; task-local isolation; no leaked timers/subscriptions; misuse of blocking functions documented and tested where detectable. |
| Processes/supervision | Privilege-drop ordering; no privileged bootstrap on failure; bounded argv/env/stdin/output; descendant policy; SIGTERM/SIGKILL/reap; restart backoff; reload readiness, bounded surge and drain. |
| TCP/Unix/UDP/TLS | FD pressure, fair accept/read/write batches, slow peers, framing bounds, explicit TLS verification, control-socket permissions and live/stale replacement ownership; close/pause from inside callbacks. |
| Host response contract | Declared body lengths, body-forbidden statuses, HEAD, header validation, write/end/drain failure semantics, buffered versus streaming behavior and exactly-once terminal notification in every real supported host. |
| Accepted new features | Every accepted feature meets its own contract, cancellation/abuse/resource tests, real-driver support claims and end-to-end performance budget; verify negligible unused-path cost. |
| Supported platforms | PHP 8.4 and 8.5 stable/lowest dependencies, clean no-dev install, portable runtime without PCNTL, and each advertised OS/real host. Verify unsupported capabilities fail explicitly. |

Add bounded, reproducible protocol fuzz/property tests with saved seeds and minimized failing inputs. Test both parser components and actual socket paths; a component pass cannot establish proxy/transport behavior. Use subprocess resource/time limits for malformed-input and descriptor-pressure tests.

## Workflow and completion criteria

During implementation, use targeted regressions first, then PHPForge's required sequential source-processing workflow and full checks. Respect the installed `vendor/infocyph/phpforge/resources/AGENTS.md`; do not edit vendor code.

Required final local commands include `composer ic:process`, `composer ic:tests:details`, and `composer ic:release:guard` after source changes. Run the existing benchmark commands plus representative end-to-end acceptance for meaningful performance changes. Do not duplicate expensive unchanged checks just to generate another report.

Keep PHPStan at the active maximum level and complexity budgets (function 12, class 80, dependency tree 120); maintain active Psalm, reference, comment, duplication, compatibility and formatting scope. No suppressions, exclusions, baseline growth, skipped tests or weakened assertions to hide a finding.

Every finding closes with: affected files, regression evidence, behavior/compatibility impact, runtime coverage and remaining limitations. Preserve unchanged public named arguments; document every intentional 2.0 contract break and security-default change.

Release only after all required CI jobs succeed on the final source revision, including real optional-runtime lanes, clean production install and applicable interoperability/soak checks. Record the commit, commands, environment, results and artifact locations. Keep host, container, CI and cross-project evidence separate. Historical green runs do not certify a later revision.

No production source, tests or dependency constraints were changed by this planning audit. Implementation starts with phase 0 and proceeds toward the single 2.0.0 target; all remediation and release gates remain open.
