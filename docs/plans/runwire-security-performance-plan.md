# Runwire 2.0 security, lifecycle and sustained-performance plan

Audit date: 2026-09-25. Source revision: `7ab48fcf224ee86838e5bf82a50b998c2aaa8a90` (local tag `1.0`).

Target: **2.0.0**, consolidating the security, correctness and runtime-contract work into one major release. Status: **all implementation batches B0-B5 are complete** on `feature/next-edition`; all RW findings are closed; F-01 through F-05 are retained in 2.0 after their required correctness, bounded-resource, performance/interoperability and quality gates passed. Certified implementation/docs evidence head `3ee339350d72264e2ad87fe8642a5018659cb4a8` completed **26/26 checks with zero failures and zero unresolved review threads**. This final plan sweep changes documentation only. Merge, tag and release remain maintainer-controlled actions. The release-duration workflow is a mandatory pre-tag release gate, not unfinished implementation work. This document does not certify the absence of vulnerabilities.

## Implementation tracker

Updated: 2026-09-26. Working branch: `feature/next-edition`. Implementation baseline: `1bd9ae9a00f352714070177e4cfb01c811231b0e`. Certified implementation/docs evidence head before this final plan-only sweep: `3ee339350d72264e2ad87fe8642a5018659cb4a8`.

This tracker is part of the implementation record. Update it in every implementation batch; do not mark a batch complete until its code, targeted regression evidence and relevant quality checks are present on the branch. Keep the pull request open throughout implementation so CI and review findings feed back into the remaining batches.

| Batch | Scope | Status | Exit evidence |
| --- | --- | --- | --- |
| B0-A | Plan tracker and implementation PR | Complete | Tracker committed; PR opened before production changes |
| B0-B | Deterministic regressions for RW-01–09 and RW-17–21 | Complete — all reproduced findings have durable regression coverage and final-matrix QA | Reproductions committed in existing suites; bounded subprocesses where required |
| B0-C | 2.0 lifecycle, response, reset-retirement and coroutine ownership contracts | Complete | State transitions and public migration decisions recorded |
| B0-D | Per-driver capability/policy ownership matrix; baseline resource/performance budgets; feature decision framework | Complete — capability/policy decisions certified with B2 host matrix QA; F-04/F-05 decision criteria were carried into B4 and both ultimately passed | Driver table, supported matrix, budget evidence and feature decision criteria recorded |
| B1-A | HTTP/1 continuation/framing/timeouts — RW-01/02/03 | Complete — exact-head QA green | Targeted adversarial regressions green |
| B1-B | Select loop/timers/callback ownership — RW-04/17/21 | Complete — portable fallback plus B3-A scalable-capacity closure exact-head QA green | Loop/descriptor/timer/UDP regressions green |
| B1-C | Error/TLS/Unix ownership hardening — RW-05/07/08 | Complete — exact-head QA green | Redaction, mTLS-policy and live-socket regressions green |
| B1-D | HTTP/3 body delivery and response framing — RW-19/18 | Complete — boundary/framing correctness plus B3-B aggregate fairness exact-head QA green | Fragmentation/coalescing and cross-writer response corpus green |
| B2-A | Terminal lifecycle, reset isolation and failure containment — RW-06/09/12/18 | Complete — terminal accounting, unhealthy latch, asynchronous terminal containment and worker retirement QA green | Common lifecycle suite green across native and hosts |
| B2-B | Shared-loop coroutine request scopes — RW-20 / F-02 | Complete — native loop attachment, concurrent progress, cancellation and scheduler-policy preservation QA green | Concurrent native requests, timers and cancellation progress together |
| B2-C | Truthful host capabilities and policy delegation — RW-22; GC disposition RW-14 | Complete — enabled-only host capability matrix and single lifecycle GC ownership QA green | Real-host capability matrix or explicit unsupported disposition |
| B3-A | Scalable native loop — F-01 / RW-04 capacity closure | Complete — ext-event backend, bounded SelectLoop fallback and >1024-descriptor lane exact-head QA green | Supported backend exceeds SelectLoop ceiling with bounded behavior |
| B3-B | HTTP/2/3 work accounting, deadlines and aggregate admission — RW-10/11/19 / F-03 | Complete — per-turn H2 work, H3 control/progress limits, worker-wide byte budget and pressure metrics exact-head QA green | Fairness and worker-wide resource limits proven |
| B3-C | Process-tree/platform hardening — RW-13 | Complete — raced descendant ownership, process-group termination, detached cleanup and platform regressions exact-head QA green | Descendant/reap/cancellation/platform regressions green |
| B3-D | Duplication, CI provenance and optional-feature handoff — RW-15/16, F-04/F-05 | Complete — shared Content-Length owner, version/tag preservation policy and automated dependency cadence exact-head QA green; final F-04/F-05 decisions moved to B4 | P2 dispositions recorded; version/tag refs preserved; update policy green; optional-feature decision ownership handed to B4 |
| B4 | Sustained-performance and release certification | **Complete** — HTTP/1/HTTP/3 smoke/interoperability lanes green; F-04/F-05 acceptance evidence green; exact-head implementation matrix green | Implementation acceptance is complete; the longer release-duration workflow remains a separate mandatory pre-tag release gate |
| B5 | Migration docs, beta/RC evidence and exact-head final candidate | **Complete** — all findings closed; migration/public docs synchronized; clean production install, package-content gate, consumer scan and certified 26/26 matrix green at `3ee339350d72` | Branch implementation is release-candidate ready; merge/tag/release remain maintainer-controlled and require the pre-tag release-duration gate |

### Finding tracker

| Finding | Priority | Batch | Status |
| --- | --- | --- | --- |
| RW-01 HTTP/1 parser continuation | High | B0-B / B1-A | Closed — exact-head QA green |
| RW-02 empty Transfer-Encoding framing | High | B0-B / B1-A | Closed — exact-head QA green |
| RW-03 silent/idle HTTP expiry | High | B0-B / B1-A | Closed — exact-head QA green |
| RW-04 SelectLoop descriptor-ceiling failure | High | B0-B / B1-B / B3-A | Closed — portable fallback is bounded and ext-event capacity lane exceeds 1024 descriptors |
| RW-05 HTTP/2 exception-text disclosure | High | B0-B / B1-C | Closed — exact-head QA green |
| RW-06 failed reset does not retire worker | High | B0-B / B2-A | Closed — unhealthy latch and native/Swoole/host retirement QA green |
| RW-07 explicit TLS verification overwritten | P1 | B0-B / B1-C | Closed — exact-head QA green |
| RW-08 live Unix socket replacement | P1 | B0-B / B1-C | Closed — exact-head QA green |
| RW-09 streaming request lifetime mismatch | High | B0-B / B2-A | Closed — terminal lifecycle/admission ownership and cross-driver QA green |
| RW-10 HTTP/2 per-turn work accounting | P1 | B3-B | Closed — bounded frame processing per event-loop turn exact-head QA green |
| RW-11 HTTP/3 control/deadline accounting | P1 | B3-B | Closed — shared control-byte accounting and request/QPACK progress deadlines exact-head QA green |
| RW-12 inconsistent failure containment | P1 | B2-A | Closed — lifecycle failure containment and retirement QA green |
| RW-13 process-tree/detached cleanup | P2 | B3-C | Closed — raced descendants, process groups, detached cleanup and repeated termination exact-head QA green |
| RW-14 duplicated host GC ownership | P2 | B2-C | Closed — duplicate FrankenPHP/RoadRunner per-request GC removed; lifecycle policy is sole owner |
| RW-15 duplicated validation/security owners | P2 | B3-D | Closed — Content-Length security parsing centralized without merging protocol-specific semantics; exact-head QA green |
| RW-16 mutable CI/dependency provenance | P2 | B3-D | Closed by policy — preserve reviewed version/tag refs, retain automated dependency update cadence, no SHA ref conversion |
| RW-17 stranded due timers | P1 | B0-B / B1-B | Closed — exact-head QA green |
| RW-18 inconsistent host response framing | P1 | B0-B / B1-D / B2-A | Closed — framing, terminal writer contract and lifecycle containment QA green |
| RW-19 HTTP/3 read-boundary body behavior | High | B0-B / B1-D / B3-B | Closed — boundary-invariant bounded delivery plus aggregate fairness and worker-wide admission exact-head QA green |
| RW-20 coroutine waits block native loop | P1 | B0-B / B2-B | Closed — native requests attach to the runtime-owned loop without nested driving; cancellation and policy QA green |
| RW-21 UDP callback close crash | P1 | B0-B / B1-B | Closed — exact-head QA green |
| RW-22 capability reporting exceeds enabled support | P1 | B0-D / B2-C | Closed — host capabilities now report enabled Runwire integration facts rather than host-product potential |

### Feature decision tracker

| Feature | Status | Decision point |
| --- | --- | --- |
| F-01 scalable native loop | Complete | ext-event backend selected when available; SelectLoop remains bounded fallback |
| F-02 shared-loop coroutine request scopes | Complete | B0-C contract and B2-B native shared-loop integration certified |
| F-03 worker-wide resource admission/pressure | Complete | bounded request/stream defaults, shared worker byte budget and pressure metrics exact-head QA green |
| F-04 bounded stream-to-response transfer | Keep in 2.0 — bounded transfer, TLS/slow-reader coverage, stable helper-vs-manual gate and exact-head QA passed | Retained as `ResponseTransfer::stream()` on the existing writer/coroutine ownership model |
| F-05 native WebSocket serving | Keep in 2.0 — bounded native HTTP/1 RFC 6455 implementation, independent PHP interoperability, slow-reader evidence and exact-head QA passed | Retained for native HTTP/1 only; compression and HTTP/2/3 WebSocket modes remain unclaimed |

### F-04 / F-05 2.0 decision tracker

F-04 and F-05 were treated as binary 2.0 decisions. Both completed every implementation acceptance checkpoint and are **retained in 2.0**. The rows below are the final decision record.

| ID | Feature | Checkpoint | Status | Evidence / exit condition |
| --- | --- | --- | --- | --- |
| F04-1 | Bounded stream-to-response transfer | API/ownership design | Complete | `ResponseTransfer` reuses `ResponseWriterInterface`, coroutine cancellation and existing terminal ownership; no second response model |
| F04-2 | Bounded stream-to-response transfer | Backpressure, fairness, cancellation and source ownership regressions | Complete | Dedicated transfer tests cover pressured writes, cooperative yielding, blocked-source cancellation and caller/source ownership |
| F04-3 | Bounded stream-to-response transfer | Real HTTP/1 + TLS slow-reader path | Complete — exact-head QA green | Native TLS HTTP/1 test streams through intentionally small transport watermarks and a slow reader |
| F04-4 | Bounded stream-to-response transfer | Helper-vs-manual throughput | Complete — stabilized performance gate passed | Exact-head PHP 8.4: 5191.538 vs 5291.444 MiB/s (-1.888%, CV 2.618/3.498%); PHP 8.5: 1660.119 vs 1673.261 MiB/s (-0.785%, CV 0.298/0.299%); 5% regression budget |
| F04-5 | Bounded stream-to-response transfer | Full PHPForge/runtime/package matrix | Complete — exact-head matrix green | 26/26 exact-head checks completed with zero failures; Security Report intentionally skipped by workflow; zero unresolved review threads |
| F04-D | Bounded stream-to-response transfer | 2.0 decision | Keep in 2.0 | Correctness, ownership, TLS/slow-reader, stable performance and exact-head quality gates passed |
| F05-1 | Native WebSocket serving | Bounded frame parser and limits | Complete | Masking, incremental input, minimal-length rules, control frames and shared byte budget covered |
| F05-2 | Native WebSocket serving | HTTP/1 upgrade and connection ownership handoff | Complete | HTTP request terminalizes at 101 while the existing connection transfers to WebSocket session ownership |
| F05-3 | Native WebSocket serving | Handshake/origin/subprotocol security | Complete | RFC key/version/upgrade validation, explicit browser-origin policy and subprotocol validation covered |
| F05-4 | Native WebSocket serving | Message/control lifecycle | Complete | Fragment reassembly, UTF-8, ping/pong, close codes/deadlines, message ceilings and worker drain implemented |
| F05-5 | Native WebSocket serving | Slow-reader/backpressure and worker-wide byte accounting | Complete — exact-head QA green | Reads stop under write pressure; parser/fragment buffers share worker byte budget |
| F05-6 | Native WebSocket serving | Capability truth | Complete | Native runtime advertises WebSocket only after native HTTP/1 implementation exists; host adapters remain unchanged |
| F05-7 | Native WebSocket serving | Independent interoperability evidence | Complete — PHP-native exact-head lane green on PHP 8.4/8.5 | PHP 8.4: 451,007 messages, median 43,949.085 msg/s, CV 1.050%; PHP 8.5: 182,076 messages, median 17,640.028 msg/s, CV 0.844%; raw PHP stdlib client validates handshake, text/binary echo, ping/pong and close without importing Runwire WebSocket classes |
| F05-8 | Native WebSocket serving | Repeated trial + slow-reader + release-gate soak | **Complete for implementation acceptance** | Five-trial PHP-native smoke and slow-reader pressure are green; the configured manual pre-tag workflow extends measured trials and soak to release duration as a release gate |
| F05-9 | Native WebSocket serving | PHPForge/static quality | Complete — exact-head QA green | No complexity/type suppressions; PHPForge QA/analysis, PHPStan and Psalm are green |
| F05-D | Native WebSocket serving | 2.0 decision | Keep in 2.0 | Native HTTP/1-only scope retained after security, bounded-resource, independent interoperability, slow-reader and exact-head quality gates passed |

**Release checklist boundary:** implementation acceptance is complete. Before tagging 2.0.0, the maintainer must invoke the benchmark workflow's certification mode on the exact release candidate so its 30-second warmup, five 180-second trials and 30-minute soak produce durable artifacts. This is a pre-tag release action, not an open B0-B5 implementation item.

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
- Request reset, lifecycle metrics and lifecycle GC have one owner: Runwire. Host adapters do not add unconditional per-request `gc_collect_cycles()`; B2-C removed duplicated ownership and certified the single-owner policy.
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

- B3-B added an aggregate queued/buffered-memory admission budget with a **64 MiB default per worker**. It covers Runwire-owned request bodies, pending response queues and protocol queues that can otherwise multiply per-stream limits. Accounting uses actual owned queued bytes rather than reserving every per-stream maximum up front.
- B3-B changes the default active request/stream policy from unbounded-by-count to **256 active requests** and **256 active multiplexed streams per worker**, while preserving explicit user configuration and protocol-local lower ceilings.
- B3-A gives the portable `SelectLoop` fallback a conservative **256 concurrently admitted native connections per worker** unless the user explicitly configures a lower value. Higher defaults require a scalable backend proven by the B3-A capacity suite; an accelerated backend may use a larger measured limit without changing the fallback safety cap.
- Per-turn work budgets remain explicit. B3-B must make HTTP/2 frame parsing and HTTP/3 control-stream work consume those budgets instead of materializing unbounded ready work before accounting.
- Generic TCP/Unix `ConnectionLimits` keep optional idle/lifetime timeouts because Runwire cannot impose HTTP policy on generic streams. Managed HTTP keeps finite protocol deadlines.

These values are conservative release defaults, not throughput claims. B4 may lower them for memory safety or raise accelerated-backend concurrency only when production-equivalent measurements and soak evidence justify it.

### Final feature dispositions

**F-04 — bounded stream-to-response transfer: accepted for 2.0.** `ResponseTransfer::stream()` stays inside the existing response ownership model and reuses `ResponseWriterInterface`, coroutine cancellation, bounded chunks, cooperative yielding and writer drain/backpressure. Plain/TLS, slow-reader, cancellation/source-ownership and helper-vs-manual performance evidence passed the acceptance gate.

**F-05 — native WebSocket serving: accepted for 2.0.** The retained surface is native HTTP/1 RFC 6455 upgrade/session support with compression disabled. It reuses existing connection, cancellation, admission and lifecycle ownership and enforces handshake, origin/subprotocol, masking, UTF-8, fragmentation, message ceilings, heartbeat/close deadlines, slow-reader backpressure and worker-byte accounting. Independent PHP-stdlib interoperability and repeated-trial evidence passed.

Final 2.0 feature disposition: **F-01, F-02, F-03, F-04 and F-05 are all accepted and implemented.** F-04/F-05 passed the binary B4 decision gates; no feature remains in a deferred, conditional or half-supported state.

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

This roadmap is now a final implementation record. F-01 through F-05 all satisfied their scoped acceptance criteria and are included in the 2.0 branch. The table preserves the rationale, bounded-security/performance contract and final release decision.

| Feature | Scope and rationale | Security/performance acceptance | Release decision |
| --- | --- | --- | --- |
| F-01: supported scalable native loop selection | Tested accelerated backend selected/injected through the existing loop abstraction and worker construction; selected backend and fallback limitations are observable. | More than 1,024 actual descriptors on the accelerated backend; bounded timers/I/O batches, cancellation/drain and controlled fallback behavior. | **Included in 2.0 — Complete.** |
| F-02: multiple coroutine request scopes on one running loop | Native servers overlap waiting requests using one owned scheduler, with per-request task groups and cancellation while preserving standalone coroutine use. | Isolation/cancellation under concurrent tenants; bounded task/waiter/backlog budgets; no nested request loop-driving. | **Included in 2.0 — Complete.** |
| F-03: worker-wide resource admission and pressure reporting | Existing admission, metrics and supervision owners enforce aggregate request/stream/queued-byte limits and observable pressure/rejection reasons. | Limits apply before expensive allocation where possible; saturation/recovery, bounded metrics and worker-wide byte accounting are covered. | **Included in 2.0 — Complete.** |
| F-04: bounded stream-to-response transfer | `ResponseTransfer::stream()` provides cancellable bounded transfer for already-authorized streams while reusing writer backpressure and coroutine ownership. | No whole-file buffering; bounded cooperative work; source ownership/cancellation/TLS slow-reader coverage; stabilized helper-vs-manual gate stayed within the 5% budget. | **Included in 2.0 — Complete.** |
| F-05: native WebSocket serving | Native HTTP/1 RFC 6455 upgrade and bounded session/message API reuse transport, cancellation, admission and lifecycle ownership. | Explicit origin policy, masking/UTF-8/frame validation, message/fragment ceilings, heartbeat/close deadlines, slow-reader backpressure, worker-byte accounting and independent PHP interop evidence. | **Included in 2.0 — Complete; HTTP/2/3 WebSocket and compression remain explicitly unclaimed.** |

For F-05, follow the handshake, framing and security requirements of [RFC 6455](https://www.rfc-editor.org/rfc/rfc6455.html). Origin checks complement application authentication; they do not authenticate non-browser clients. Keep compression disabled initially. Any later compression support needs its own negotiation, resource and confidentiality review against [RFC 7692](https://www.rfc-editor.org/rfc/rfc7692.html).

F-04/F-05 followed the phase-0/B4 decision record: consumer/use case, existing owner, public contract, supported scope, resource ceilings, abuse/security corpus, before/after measurements, dependency cost and migration/maintenance impact were evaluated before acceptance. Both met their scoped budgets, so both are retained. Unsupported WebSocket modes remain explicitly unclaimed rather than half-supported.

Keep framework adapters, outbound database/HTTP client pools, a distributed job system and automatic global monkey-patching outside this release unless a concrete consumer requirement justifies their separate design. Runwire can integrate with such systems without reimplementing them. Optimize the highest measured sustainable successful RPM under security, correctness, latency and resource constraints; do not promise an absolute maximum across every workload.

## Implementation phases for the single 2.0.0 target

All implementation phases are complete on the working branch. The phase record below summarizes what was delivered; it is no longer a forward-looking task list.

### Phase 0 — reproducible regressions and contract decisions — complete

1. Deterministic reproductions were committed for RW-01–09 and RW-17–21 in the existing relevant suites, with bounded subprocesses for descriptor/malformed-input probes where needed.
2. Completion/cancellation/retirement transitions, response-write ownership, concurrent-reset policy and coroutine loop ownership were defined before implementation.
3. Per-driver security/resource ownership and enabled capability reporting were recorded, including RW-22.
4. Baseline resource/performance budgets were established for connections, streams, protocol queues, coroutine scheduling and worker-wide admission.
5. F-04/F-05 were evaluated using explicit bounded-resource, correctness, interoperability/performance and maintenance criteria; both were accepted and mapped to dedicated B4 evidence lanes.
6. Supported PHP/runtime/host/transport claims and migration-impact decisions were recorded.

Exit achieved: regressions, ownership contracts, feature decisions and baseline budgets were recorded before dependent implementation.

### Phase 1 — protocol, transport and loop corrections — complete

1. HTTP/1 continuation, framing and managed idle/deadline behavior were corrected (RW-01–03).
2. Permanent select failure, stranded timers and UDP callback ownership were corrected (RW-04/17/21).
3. Asynchronous error redaction, explicit TLS policy and live Unix-socket ownership were corrected (RW-05/07/08).
4. HTTP/3 incremental body delivery and host response framing were corrected with fragmentation/coalescing coverage (RW-19/18).

Exit achieved: relevant adversarial regressions are green and no high-priority protocol/transport defect remains open on a supported path.

### Phase 2 — completion, isolation and consistent runtime ownership — complete

1. The phase-0 lifecycle contract was implemented across HTTP/1/2/3, application/context, response writers and host adapters (RW-06/09/12/18).
2. Unsafe reset failure now stops admission and retires the appropriate reusable worker/runtime instance.
3. Coroutine request scopes attach to the owning loop; concurrent native requests, timers and cancellation progress without nested loop driving (RW-20 / F-02).
4. Capability reporting reflects enabled Runwire integration facts, and lifecycle GC has one owner (RW-22 / RW-14).

Exit achieved: common lifecycle/isolation suites pass and no legacy path bypasses terminal accounting or failed-reset retirement.

### Phase 3 — bounded capacity and operational hardening — complete

1. A scalable ext-event backend is selected when available; SelectLoop remains the bounded portable fallback with explicit capacity behavior (RW-04 / F-01).
2. HTTP/2/3 per-turn work accounting, progress deadlines, protocol backpressure and worker-wide queued-byte admission are implemented (RW-10/11/19 / F-03).
3. Process-tree ownership, descendant termination and detached cleanup were hardened (RW-13).
4. F-04/F-05 were implemented in existing transport/lifecycle owners with bounded contracts and abuse/resource tests.
5. Valid duplicated validation ownership and dependency/CI provenance policy were resolved without weakening PHPForge standards or replacing reviewed version/tag refs with SHAs (RW-15/16).

Exit achieved: bounded resources and fair progress are verified at supported capacity; every P1/P2 finding has a closed correction or explicit final policy.

### Phase 4 — sustained-performance and feature acceptance — complete

The existing benchmark/PHPForge owners were extended rather than replaced.

- HTTP/1 real-server evidence records completed/successful/error/timeout/correctness counts, latency, CPU/RSS and environment metadata.
- HTTP/3 retains dedicated real interoperability/soak ownership.
- F-04 uses repeated, warmed, interleaved helper-vs-manual bounded-transfer trials plus real TLS slow-reader coverage; the stabilized exact-head medians stayed within the 5% regression budget.
- F-05 uses a raw PHP-stdlib RFC 6455 client that imports no Runwire WebSocket classes and covers handshake, text/binary echo, ping/pong, close behavior, repeated trials and slow-reader pressure.
- Shared-runner variance is recorded rather than hidden, and performance claims remain scoped to the measured workload/environment.
- Only complete correct responses count toward throughput evidence; numeric throughput without correctness accounting is not accepted.

Implementation exit achieved: correctness/isolation, F-04/F-05 acceptance evidence and the complete exact-head branch matrix are green. The longer 30-second warmup + five 180-second trials + 30-minute soak mode remains intentionally configured as the **maintainer-run pre-tag release certification**, not an open implementation phase.

### Phase 5 — beta, migration and final candidate — complete

1. Migration guidance, API examples, deployment/security guidance, capability documentation and benchmark methodology were updated for 2.0.
2. Historical-plan links were replaced with the active 2.0 tracker without restoring removed historical plans.
3. Every RW finding has a closed status and regression/policy evidence.
4. Production `--no-dev` install, package-content verification, consumer scan and the certified implementation/docs matrix are green.
5. F-04 and F-05 public documentation reflects the accepted 2.0 scope; unsupported WebSocket compression and HTTP/2/3 modes remain explicitly unclaimed.

Exit achieved: branch implementation is release-candidate ready. Merge, tag and release remain maintainer-controlled. Before tagging 2.0.0, run and retain the configured release-duration certification artifacts on the chosen exact release candidate.

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

**Implementation status: complete.** B0-B5, RW-01 through RW-22, and F-01 through F-05 are closed on the working branch for the single 2.0.0 target. Certified implementation/docs evidence head `3ee339350d72264e2ad87fe8642a5018659cb4a8` completed 26/26 checks with zero failures and zero unresolved review threads. This final plan sweep is documentation-only. The human-triggered release-duration certification remains the mandatory pre-tag release gate; merge, tag and release remain maintainer-controlled actions.
