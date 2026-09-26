# Runwire 2.0 class/file consolidation plan

Date: 2026-09-26. Source baseline: `ee56528a1c78b77e97d1bd26fcd43a2d14dd5c09`.
Status: **implementation complete; final runtime revision verified by the full PR matrix**. Target: **2.0 before release**, including documented breaking changes where justified. Release-duration certification/soak remains an explicit pre-release performance gate rather than being inferred from PR smoke evidence.

Governing instructions: [PHPForge engineering principles](../../vendor/infocyph/phpforge/resources/engineering-principles.md), especially “Structural Simplification, Type Budget And Call-Hop Reduction”, “Autoloadable Symbol And File Behavior”, and “OPcache Capacity, Warm-Up And Observability”. The refreshed graphify graph was used for navigation; source inspection determines ownership and compatibility.

## Decision

Reduce unnecessary types and delegation by moving behavior into its cohesive owner. Start with duplicate HTTP/2 validation representations and simple single-owner helpers. Preserve meaningful protocol, security, resource and lifecycle boundaries. Measure loaded/cacheable files, allocations, startup and sustained successful RPM before and after each batch.

Do not promise a percentage reduction or set an arbitrary maximum file count. The first inspected shortlist offers **seven potential file removals**; a second group offers **eight more conditional removals**. These are estimates of source deletions, not guaranteed OPcache savings. Reject individual merges when they worsen correctness, ownership, cognitive complexity or measured throughput. Broader reductions require the same evidence and an explicit candidate record.

## OPcache capacity: what the concern means

`opcache.max_accelerated_files` controls hash-table capacity, not a per-library quota. PHP documents a default of 10,000, rounded up to an available table size (16,229 at that default), and a configurable maximum of 1,000,000. Memory capacity is a separate constraint. Capacity planning must cover the application and dependencies sharing the relevant cache instance. [PHP configuration reference](https://www.php.net/manual/en/opcache.configuration.php#ini.opcache.max-accelerated-files).

Installed files do not all occupy cache entries just because Composer lists their classes. Count the executed/compiled workload, alternate routes, workers, warm-up and deployment paths. Track actual `num_cached_scripts`, `num_cached_keys`, `max_cached_keys`, memory and restart statistics. Count neither classes nor worker count as a direct multiplier for cache entries. Process/SAPI/cache-sharing topology must be recorded. [PHP status reference](https://www.php.net/manual/en/function.opcache-get-status.php).

Composer's optimized classmap avoids filesystem searches for known classes; authoritative mode also rejects classes absent from that map. Neither merges source files. Validate both ordinary PSR-4 and production optimized loading. Consumer root configuration controls deployment choices; this repository's settings are not proof of consumer settings. [Composer optimization reference](https://getcomposer.org/doc/articles/autoloader-optimization.md).

Preloading trades baseline memory and restart requirements for loading benefits; it is a separate measured option, not the default consolidation technique. Do not introduce eager loading of all Runwire code. [PHP preloading reference](https://www.php.net/manual/en/opcache.preloading.php).

## Measured baseline

Inventory from `src/**/*.php`; tests, benchmarks, vendor code and graph artifacts are excluded from production counts.

| Measure | Current value |
| --- | ---: |
| Production PHP files | 348 |
| Class declarations | 294 |
| Enum declarations | 36 |
| Interface declarations | 18 |
| Files under an `Internal` directory | 112 |
| Source bytes on disk | 1,252,257 |
| Test/fixture PHP files, separate from production | 113 |
| Benchmark PHP files, separate from production | 12 |

These are filesystem and declaration counts, not executable-line metrics. Small-file and single-method ratios in PHPForge are non-blocking review triggers with exclusions for meaningful values, exceptions and other legitimate types. Do not substitute physical line counts or lexical reference counts for those metrics or for proof that a type is unused.

| Source area | Files |
| --- | ---: |
| HTTP, including HTTP/1, HTTP/2, HTTP/3, HPACK, QPACK and QUIC | 109 |
| Runtime | 58 |
| Coroutine | 38 |
| Supervisor | 37 |
| Network | 24 |
| Root-level types | 16 |
| Process | 14 |
| Metrics | 12 |
| Loop | 11 |
| Exception | 10 |
| WebSocket | 8 |
| Protocol | 7 |
| Control | 2 |
| Cancellation/Internal and root Internal | 2 |

Separate PHP 8.5.4 CLI processes, with `-d opcache.enable_cli=1`, produced these bounded probes:

| Probe | Included Runwire files | Cached Runwire files | Sum of Runwire script cache memory |
| --- | ---: | ---: | ---: |
| Require the existing Composer autoloader only | 0 | 0 | 0 bytes |
| One completed `RuntimeApplication` request, buffered empty body and callback writer | 43 | 43 | 404,192 bytes |
| Same request through attached `CoroutineRequestHandler` and `SelectLoop` | 63 | 63 | 688,776 bytes |

The host reported configured capacity 10,000, effective `max_cached_keys=16229`, and `cache_full=false`. Filter both `get_included_files()` and `opcache_get_status(true)['scripts']` by the absolute `src/` prefix. Sum per-script `memory_consumption`; this excludes shared table/interned-string overhead and Composer/dependency scripts. Each request probe asserts completed context before reading status.

### Matched consolidation baseline

The durable footprint harness is now `benchmarks/opcache_footprint.php` and the benchmark workflow captures all three modes on PHP 8.4/8.5. Before production consolidation, the same harness logic was run against the packaged `dc78bdc9d8684dcff48dac197d6cc0f21ed86043` source on PHP 8.4.23 CLI with OPcache enabled:

| Mode | Source PHP files | Included Runwire files | Cached Runwire files | Runwire script cache memory |
| --- | ---: | ---: | ---: | ---: |
| Bootstrap | 348 | 0 | 0 | 0 bytes |
| Lifecycle request | 348 | 43 | 43 | 404,168 bytes |
| Attached coroutine request with one yield | 348 | 65 | 65 | 692,776 bytes |

This matched probe is only for structural before/after comparison on the same host. Release performance acceptance continues to use the existing PHPBench/native repeated-trial workflow and sustained certification procedure.

These probes used the development installation and synthetic in-process requests. They are **not** native-server, FPM, complete consumer-application, peak deployment, or steady-state performance measurements. Temporary evidence is `/tmp/runwire-footprint.php` and `/tmp/runwire-footprint-{bootstrap,lifecycle,coroutine}.json`; these paths are local conveniences, not durable release artifacts. Phase C0 must commit a reproducible harness and retain its results before implementation.

## Candidate register

All paths below are relative to `src/`. A single source consumer is a review lead, not permission to erase a contract. Check tests, documentation, Composer discovery, external consumers and reflected/dynamic uses before deleting anything.

| ID | Current files / owner | Proposed consolidation | Potential removed files | Required safeguards |
| --- | --- | --- | ---: | --- |
| C1 | `Http/Http2/Internal/{RequestHeaderValidator,ValidatedRequestHead,HeaderValidationException}.php`; `Http/Http2/Internal/RequestStreamProcessor.php` | Use the existing shared `Http/Internal` validator, result and exception directly at the HTTP/2 boundary. Retain the `HTTP/2` diagnostic label and existing stream-error mapping there. Eliminate the wrapper, copied result allocation and exception rewrap. | 3 | Pseudo-header, authority, forbidden header, Content-Length and trailer corpus remains unchanged; preserve connection-vs-stream failure scope. Document any removed exception/result identity even though the namespace is Internal. HTTP/3 retains its own protocol mapping. |
| C2 | `Loop/Internal/SelectFailurePolicy.php` → `Loop/SelectLoop.php` | Move recoverable/permanent select-error classification to a private method of its only source owner. | 1 | EINTR and pruned-descriptor recovery must still work; permanent errors must fail rather than spin. Retain portable descriptor ceiling. |
| C3 | `Coroutine/Internal/SchedulerContext.php` → `Coroutine/Internal/FiberScheduler.php` | Replace the two-field immutable holder with typed readonly loop and policy fields directly on the scheduler. | 1 | Preserve shared policy identity, loop ownership, task bounds and all diagnostics. No scalar/array replacement of cancellation or task state. |
| C4 | `Http/Http2/Internal/RequestStreamLookup.php` → `Http/Http2/Http2Connection.php` | Remove the attach-then-forward object if a direct closure over the owning connection's initialized request processor is safe. | 1 | Trace constructor callbacks before changing capture: no access to uninitialized `requests`, no altered synchronous transport behavior, new retained cycles or late callbacks after close. If initialization safety needs the object, keep it and record why. |
| C5 | `Network/Internal/ConnectionCallbackDispatcher.php` → `Network/Connection.php` | Fold callback dispatch/invocation into private connection methods, retaining first-failure propagation after cleanup. | 1 | Run every close callback, preserve the originating exception, keep reentrant close safe. Measure the owner's complexity before merging; reject if it breaches current limits. Keep callback-ownership enforcement separate unless independently justified. |
| C6 | `Runtime/Internal/DevelopmentFileScanner.php` → `Runtime/Internal/DevelopmentWatcher.php` | Consider local snapshot/hash methods for the single watcher owner. | 1 | Preserve deterministic ordering, maxFiles bound, missing-file behavior and debounce/reload semantics. This helps development structure; claim zero normal production-cache savings unless the feature is actually loaded there. |
| C7 | `Coroutine/Internal/YieldSuspension.php` → existing scheduler/suspension flow | Evaluate a scheduler-owned yield operation with no dedicated per-yield object. | 1 | Conditional experiment only: preserve the closed suspension contract, fairness, cancellation and invalid-suspension detection. No ambiguous magic scalar or mutable singleton. Keep the class if simplification weakens those guarantees. |
| C8 | `Runtime/Host/RuntimeApplication.php` and `Runtime/ApplicationLifecycle.php` | Review one canonical public application/lifecycle owner for 2.0; retain the `RuntimeApplicationInterface` extension boundary. | 1 | Public constructor, factories and custom integrations make this a breaking API design decision. Retain default context construction, hooks, health and shutdown behavior. Benchmark removal of repeated delegation and migrate consumers. Defer if savings do not justify churn. |

C1–C4 were accepted for six removals. C6, C7, W2 and W4 were also accepted; C5, C8, W1 and W3 were retained after ownership/quality review. The candidate removes **11 production files, 348 → 337**, without adding replacement helper files. C5 was explicitly rejected after PHPStan/PHPForge measured `Connection` class cognitive complexity at 87, above the hard limit of 80.

### Additional candidates from the whole-library review

| ID | Current files / owner | Proposed direction | Potential removed files | Decision constraints |
| --- | --- | --- | ---: | --- |
| W1 | `Runtime/Host/HostDriverFactory.php` → `Runtime.php` | Consider placing the one-caller driver-construction match in the existing private host-runtime setup path. | 1 | Retain every driver-specific argument, lazy extension loading and unsupported-driver failure. The factory is a public symbol; document removal. Runtime is already a large orchestrator: keep the factory if merging makes ownership or complexity worse. |
| W2 | `Http/Http3/FrameWriter.php` → `Http/Http3/Frame.php` | Evaluate a canonical `Frame::encode()` operation for the currently single-method writer. | 1 | Keep incremental parsing separate. Preserve exact varint and payload bytes; update all transport/codec consumers. This is a public API break and an ownership choice, not an assumed speedup. Do not apply automatically to HTTP/2's richer control-frame writer. |
| W3 | `Http/Http1/Enum/ParserState.php` → `Http/Http1/Http1Connection.php` | Review whether private typed state constants can provide equivalent safety for the one-source-owner parser state. | 1 | Conditional only: preserve closed transitions, exhaustive handling and static analysis. Current enum type safety is a reason to keep it. Merely replacing an enum property with an unconstrained string/int is insufficient. Check external references before removal. |
| W4 | `Supervisor/Internal/RestartTracker.php` and `RestartAttempt.php` → `RestartCoordinator.php` | Evaluate one bounded restart owner with direct count/delay parameters, avoiding a separate tracker/result round trip. | 2 | Preserve per-group restart history, monotonic windows, backoff, timer cancellation, reload/recycle generation semantics and failure metrics. Keep the tracker if combining rate-budget state and orchestration increases complexity. No untyped tuple substitute or removal of restart limits. |

### Whole-library disposition matrix

The review covers all **348 production files**, not only `Internal`. [The companion inventory](runwire-2.0-class-file-inventory.csv) lists every source path, declaration kind, named-method count, planning disposition, reason and declared responsibility. The inventory was generated from all source text; candidate bodies, callers and relevant tests were inspected directly. This is a complete structural screening with targeted candidate analysis, not a new exhaustive security audit or proof that every retained implementation is optimal. “Retain” means no justified deletion identified in this pass, not permanent exemption from future evidence.

| Area | Files | Disposition and boundary rationale |
| --- | ---: | --- |
| Root API | 16 | Retain distinct HTTP/stream/datagram entry points, immutable options/context/deadline and cancellation handles. Source/token separation limits mutation authority. Runtime hosts W1 but is not itself removed. |
| Cancellation | 1 | Retain shared cancellation state: token/source/subscription identities and observer disposal are meaningful. |
| Control | 2 | Retain validated control policy and bounded local command server; do not merge control-plane commands into the public supervisor merely to save a file. |
| Coroutine | 38 | C3/C7 candidates; retain structured scope/task/future/deferred roles, queue/wait state, synchronization primitives, async adapter and typed failures. Future consumers and Deferred producers have different authority. |
| Exception | 10 | Retain failure taxonomy, previous causes and aggregated reset/shutdown failures. Empty exception classes still support consumer catch contracts. |
| HTTP | 109 | C1/C4/W2/W3 candidates. Retain separate wire versions, codecs, parsers, flow control, request/response owners and QUIC boundary. QPACK checked arithmetic and readiness masks validate hostile/native inputs. HPACK Huffman machinery is already reused by QPACK. |
| Internal time | 1 | Retain shared monotonic conversions and overflow-safe deadline arithmetic used across independent owners. |
| Loop | 11 | C2 candidate. Retain selectable event/select/Swoole implementations, loop contract, timer queue and diagnostics capability. Factory also enforces the portable connection ceiling. |
| Metrics | 12 | Retain explicit snapshot/schema boundaries, policies and request-ID generator/policy interfaces. The small default generator is a selectable implementation, not dead code or an empty wrapper. |
| Network | 24 | C5 candidate. Retain TCP/Unix/UDP/TLS distinctions, bounded queues/budgets, exclusive callback ownership and structured write results. Shared socket probing is real capability detection, not an identity wrapper. |
| Process | 14 | Retain command validation/preparation, input/output ownership, handles, termination policy and process-tree mechanics. Similar names `ProcessTermination` and `ProcessTerminator` represent deadline state versus OS signaling, not duplicate layers. |
| Protocol | 7 | Retain codec contract and raw/line/length-prefixed providers; RawCodec's short pass-through behavior is the actual raw framing strategy. |
| Runtime | 58 | C6/C8/W1 candidates. Keep host adapters, environment/capability/driver decisions, lifecycle/admission/reset ownership and portable/prefork transport owners. Do not collapse startup capability validation into per-request logic. |
| Supervisor | 37 | W4 candidate. Retain listener-failure isolation, privilege transition, OS identity adapter, readiness channels, signal bridge, reload coordination, worker state and metrics aggregation. Single source ownership alone does not invalidate these responsibilities. |
| WebSocket | 8 | Retain upgrade validation, parser, session, frame/message distinction, close/error encoding and bounded options. Frame parsing and fragmented-message assembly have different state and limits. |

All 18 interfaces were included in the screening. No interface is selected for deletion merely because the repository provides one implementation. Public extension points, host/OS adaptation, multiple loop/codec/writer backends and the scheduler suspension contract provide concrete boundaries.

All 36 enums were included. `ParserState` is the only state-enum deletion experiment selected here. `TaskGroupFailureMode` has one internal consumer but is a public policy; `SettingIdentifier` models standardized HTTP/3 identifiers. Both stay. All exception types remain except the redundant HTTP/2 validation exception included in C1's explicit migration.

A lexical scan found eight production types without another source-file reference: `AsyncConnection`, `ResponseTransfer`, `SwooleLoop`, `ProcessRunner`, `LengthPrefixedCodec`, `RawCodec`, `CoroutineRequestHandler` and `WebSocketUpgrade`. These are consumer-facing capabilities; zero internal callers is not dead-code evidence. Do not delete them or infer unused methods from repository call counts.

Large owners include `Supervisor` (769 physical lines), `Connection` (764), `Http1Connection` (741), `WebSocketSession` (634), `Runtime` (612) and `FiberScheduler` (492). These counts include comments and are not complexity scores. They justify caution: measure actual class/function/dependency-tree complexity before candidate merges, preserve useful collaborators, and reject merges that merely trade file count for a harder-to-maintain owner.

Tests/fixtures (113 PHP files), benchmarks (12 PHP files), docs, workflows and graph output were classified separately. They are not ordinary production OPcache load and are not deletion targets. Composer currently declares no runtime library dependencies beyond PHP/platform requirements; development-tool files must not be charged to a production Runwire source budget. Review production artifacts and `--no-dev` installs rather than deleting developer tools to shrink a filesystem count.

### Keep unless new evidence establishes redundancy

- `ByteBudget`, `ByteQueue`, request-body ownership, admission control, `RequestFinalizer`, terminal/completion observers and worker stop/recycle state: explicit bounded resource and request-lifecycle responsibilities.
- `SuspensionState`, cancellation registrations, `ReadyQueue`, task-local state, typed waiters and per-stream mutable state: ordering, identity and independently managed lifetimes matter more than their size.
- Shared `ContentLengthParser`, authority/header validation and response semantics: multiple protocol consumers and security-sensitive invariants justify central ownership.
- HPACK/QPACK codecs and tables, HTTP/2/3 flow control, protocol error enums, transport abstractions, TLS and privilege/process-tree boundaries: preserve protocol semantics and real backend substitution.
- Public configuration, result and exception types; loop, request, writer, resetter, host and identity interfaces: one built-in implementation does not disprove an extension or security boundary.
- Bound server records for different transport kinds: do not replace valid typed combinations with a single nullable-property bag simply to delete two files.

Do not merge HTTP/1+2 and HTTP/3 worker owners merely because their completion callbacks resemble each other; transport ownership differs. Preserve the newly fixed rule that worker completion and retirement follow coroutine settlement and request cleanup.

## Execution and acceptance

| Phase | Work | Exit evidence | Status |
| --- | --- | --- | --- |
| C0 | Freeze baseline commit; generate a declaration/ownership inventory and loaded-file/cache harness using existing test/benchmark infrastructure. Classify keep/remove/conditional candidates. | Reproducible JSON artifacts, graph/source verification, exported-symbol map, baseline tests and stable benchmark envelope. | Complete |
| C1 | Consolidate shared HTTP header validation representations. | Three removals or a documented narrower result; protocol failure/response parity and autoload checks. | Implemented; 3 files removed |
| C2–C3 | Merge select-error policy and scheduler holder, separately reviewable. | Select/coroutine regressions; startup, allocations and loaded-file deltas. | Implemented; 2 files removed |
| C4–C5 | Simplify stream lookup and connection dispatch after lifetime/complexity review. | Constructor/reentrancy, pressure, close/error and stream-lifetime tests; memory/complexity evidence. | C4 implemented; C5 retained because merging raised `Connection` cognitive complexity to 87 (>80) |
| C6–C8, W1–W4 | Decide each conditional candidate from evidence. | Explicit keep/remove decision, public migration where applicable, per-workload results. | C6/C7/W2/W4 implemented; C5/C8/W1/W3 retained |
| C9 | Document measured consumer capacity guidance and final candidate. | Final source/type counts, workload cache deltas, no stale symbols and final-revision CI; release-duration sustained/soak remains the explicit pre-release certification gate. | Complete for implementation; release certification remains external to PR smoke |

For each batch: record old owner → new owner, net type/file change, removed calls/allocations, public effects, ownership invariants, regression commands and before/after evidence. Keep batches independently revertible. Do not let an experimental later batch block useful verified earlier simplification.

### Consolidation candidate evidence

The matched PHP 8.4.23 local footprint probe produced:

| Mode | Baseline files | Candidate files | Baseline loaded/cached | Candidate loaded/cached | Baseline cache bytes | Candidate cache bytes |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Bootstrap | 348 | 337 | 0 / 0 | 0 / 0 | 0 | 0 |
| Lifecycle request | 348 | 337 | 43 / 43 | 43 / 43 | 404,168 | 403,992 |
| Attached coroutine + yield | 348 | 337 | 65 / 65 | 62 / 62 | 692,776 | 686,504 |

Focused same-host micro-probes showed no regression signal for shared HTTP/2 header validation, HTTP/3 frame encoding, or scheduler yield. These are directional probes only; CI PHPBench and native sustained trials remain authoritative.

Decisions: **C1 accepted** (shared HTTP validation directly); **C2 accepted** (select failure policy belongs to SelectLoop); **C3 accepted** (direct readonly scheduler dependencies); **C4 accepted** (safe owner closure after constructor trace); **C5 retained** (folding callback dispatch raised `Connection` cognitive complexity to 87, above the PHPForge class limit of 80); **C6 accepted** (single-owner development scanning); **C7 accepted** (scheduler-owned yield signal removes per-yield allocation); **C8 retained** (substantial lifecycle boundary); **W1 retained** (avoid bloating Runtime); **W2 accepted** (Frame owns encoding); **W3 retained** (typed exhaustive parser state); **W4 accepted** (restart state owned by RestartCoordinator).

### Final PR evidence

The final runtime revision `580c1b679b734acff3fef23f0c9e76a527b8c37b` passed every PR workflow: Release Candidate, Source Audit, Portable Native (PHP 8.4/8.5 plus ext-event), Benchmarks, all Swoole/OpenSwoole PHP 8.4/8.5 lanes, and Security & Standards. The security matrix passed HTTP/3+QUIC on PHP 8.4/8.5, clean install, PHPForge analysis on PHP 8.4/8.5, stable/lowest QA on both versions, and PHPForge benchmark jobs. No review thread remained unresolved.

The retained benchmark artifacts reported this candidate footprint:

| CI runtime | Mode | Source PHP files | Included/cached Runwire files | Runwire script cache memory | Cache full |
| --- | --- | ---: | ---: | ---: | --- |
| PHP 8.4.26 | Bootstrap | 337 | 0 / 0 | 0 bytes | false |
| PHP 8.4.26 | Lifecycle | 337 | 43 / 43 | 404,736 bytes | false |
| PHP 8.4.26 | Coroutine + yield | 337 | 62 / 62 | 687,600 bytes | false |
| PHP 8.5.11 | Bootstrap | 337 | 0 / 0 | 0 bytes | false |
| PHP 8.5.11 | Lifecycle | 337 | 43 / 43 | 404,248 bytes | false |
| PHP 8.5.11 | Coroutine + yield | 337 | 62 / 62 | 687,040 bytes | false |

The same PR benchmark run completed five real native HTTP/1 smoke trials on each PHP version with correctness passing and low trial variance: median successful RPM was 23,413.98 on PHP 8.4 and 23,418.72 on PHP 8.5; median p95 was 41.959 ms and 41.912 ms respectively. F-04 transfer comparison and independent WebSocket evidence also passed correctness on both versions with zero WebSocket errors/timeouts/validation failures.

These CI figures are retained artifacts from the final PR run and are not compared byte-for-byte with the earlier local baseline because the hosts/PHP builds differ. The matched same-host table above remains the valid structural before/after comparison. The PR run is smoke/repeated evidence, not the 30-second warm-up + five 180-second trials + 30-minute release soak; that longer certification remains a separate pre-release gate and is not falsely claimed here.

### Measurement matrix

Use production-equivalent installs in separate temporary consumer directories; do not replace the developer's vendor tree. Test ordinary PSR-4, optimized classmaps and authoritative classmaps where the consumer supports them. Exercise each surviving public symbol first in a fresh process to expose incidental-load dependencies. Regenerate autoload metadata after source changes; scan removed FQCNs, docs and fixtures. No manual class includes, eager Composer `files` registration, custom class loader, generated mega-file or request-time code generation.

Capture bootstrap, first request, warmed steady state and recycle/restart for:

1. Native portable HTTP/1 with keep-alive and mixed routes; native prefork separately.
2. Attached coroutine requests with root/child work after response end, cancellation, reset failure and worker recycling.
3. HTTP/2 multiplexing with flow control, trailers and byte-budget pressure; HTTP/3 through supported real QUIC transport as a separate lane.
4. Supported host adapters, including FPM repeated request bootstrap; report real-host evidence separately from mocks.
5. A representative consumer app plus its other libraries, including errors, less-common routes and release reload overlap. If no consumer fixture is available, mark this gate outstanding instead of inferring whole-app capacity from Runwire alone.

Per workload report included and cached files by package, total cached keys, shared OPcache memory and interned strings, class/object allocations where profiled, worker RSS/CPU, startup/first-request latency, p50/p95/p99, successful RPM, errors, timeouts, and queue/backpressure stability. Attribute diagnostic overhead and collect snapshots outside timed request loops. Do not add unconditional production observability or a new diagnostics class per metric.

Use the existing benchmark runners and five-trial summary. PR smoke runs verify behavior only. For performance acceptance, compare baseline/candidate on matched stable infrastructure, PHP, extensions, OPcache/JIT, workload and Composer mode, with warm-up and alternating/repeated trials. Retain the current release procedure: 30-second warm-up, five 180-second measured trials and the 30-minute soak where applicable. Profile coroutine-specific changes as well as plain HTTP; one microbenchmark cannot certify all runtime modes.

Establish the smallest distinguishable regression from baseline variance before merging. Reject repeatable successful-RPM loss outside that envelope, worse tail latency outside the agreed workload budget, progressive RSS/queue growth, cache exhaustion or correctness failures. If noise prevents deciding, the performance gate stays open. No new arbitrary percentage threshold, lowered detector level or suppression to obtain a pass. At equivalent performance prefer simpler ownership. A lower file count alone is insufficient evidence of improved RPM or cache memory.

### Correctness, security and quality gates

Keep all current detectors and complexity budgets, including the principles' class/function/dependency-tree limits. Moving code into a large owner must not hide complexity or bypass scanning. Meaningful new tests target changed behavior and failure paths; do not create tests that only assert the chosen implementation layout.

Preserve exactly-once cleanup/completion, health latching before retirement, admission release, cancellation propagation, task fairness, protocol validation, byte accounting, error redaction, transport teardown and privilege/process ownership. Keep the existing native worker lifecycle regressions as mandatory acceptance for any lifecycle-related consolidation.

Follow [PHPForge agent workflow](../../vendor/infocyph/phpforge/resources/AGENTS.md): doctor/list-config/active-config, `composer ic:process` (documented fallback processors if it fails), `composer ic:tests:details`, then `composer ic:release:guard`. Verify supported PHP 8.4/8.5 stable/lowest CI, portable native, ext-event and applicable QUIC/host lanes on the final candidate. Report host, prepared environments and CI separately. A missing optional extension is an explicit verification limitation, never a reason to weaken a gate or require it for all production users.

### Deployment capacity guidance

Measure the union of scripts/keys used by the actual application and dependencies in each shared cache, including planned route coverage and release overlap. Select capacity above the observed peak with documented growth/deployment headroom; verify PHP's effective table size. Size byte memory and interned strings independently. Reserve no fixed percentage for Runwire and assume no universal consumer configuration.

Monitor cache-full, hash/OOM restarts and hit-rate trends across warm-up and load. Verify OPcache is enabled for the serving SAPI (CLI workers need their own enablement). Keep safe path/comment/optimizer settings. Warm representative code only; preloading is optional and separately benchmarked. Use controlled worker/FPM restarts or correctly scoped invalidation for immutable releases; do not expose cache-status/reset endpoints publicly.

## Final implementation verification

The consolidation implementation is complete at **337 production PHP files**, down from 348, with **11 net source-file removals** and no replacement helper files. C1–C4, C6, C7, W2 and W4 were accepted. C5, C8, W1 and W3 were retained because the evidence favored the existing boundary; notably, C5 was reverted when the merged `Connection` reached class cognitive complexity 87 against PHPForge's hard limit of 80.

The final runtime revision passed the complete PR matrix described above. PHPForge Reference Integrity, duplicate detection, comment policy, Pest, Pint, PHPCS, Deptrac, Rector, PHPStan and Psalm all pass after migrating every removed-symbol reference. The durable OPcache harness is part of the benchmark workflow, and its JSON outputs are retained as workflow artifacts.

No detector, complexity budget, reference checker or optional runtime lane was bypassed. The plan tracker and companion inventory reflect the final 337-file candidate. PR #3 remains open and unmerged for human release/merge control.

### Release boundary

This plan's implementation work is closed. The repository's longer release-duration performance certification remains intentionally separate: 30-second warm-up, five 180-second measured trials and the applicable 30-minute soak should still be run before publishing 2.0 when that release gate is invoked. PR smoke results are not relabeled as release-duration certification.

