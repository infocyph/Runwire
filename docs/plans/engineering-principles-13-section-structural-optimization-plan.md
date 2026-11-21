# Runwire — PHPForge 13-section engineering and structural optimization plan

> Scope: Runwire production PHP plus configuration, CI, and tests. Baseline `f1c99ba83140bf9829406c600296ebe1a78be491` (the CI-green head of PR #16). Read-only source sweep informed this plan; it does **not** certify that every function meets every PHPForge rule.
>
> Principles: `infocyph/PHPForge/resources/engineering-principles.md` at `c1f68be02b22a4d3377b7be91f563b12e7f68955`. Source of authority: rule IDs, not intuition.
>
> Execution: **one batch = one commit**; update this file's bottom tracker in the **same** commit as the batch changes. Do not modify main/master, auto-merge, release, or tag. PR #16 was closed WITHOUT merging on 2026-11-21; PR #17 is now based directly on `main`, already contains every PR #16 commit by ancestry, and is the single active delivery PR. Keep its draft status until checks and review are complete.

## Contracts, evidence, and non-goals

- Preserve public classes, constructor parameter names, named-argument behavior, HTTP/1–3 and WebSocket wire behavior, timeout/cancellation semantics, security checks, process/fork ownership, independent QUIC interoperability, and supported PHP 8.4–8.6 compatibility.
- Primary optimization metric: sustained *successful* request RPM with bounded latency, worker memory, open connections and queue depth. Supporting metrics: allocations per request, autoloaded/compiled files, warm/cold init, parser/callback calls, duplicate conversions, call depth and navigation cost.
- **No numeric file-removal quota.** Remove a type only if ownership, contract, usage and tests justify it. Do not fuse protocol-specific state or public autoloadable symbols into unrelated files just to lower the count.
- Before editing: inspect all affected callers (including tests and docs), dispatch/lifecycle/security boundaries, and applicable rules. Reuse PHPForge commands when available; record unavailable local profiling honestly. Do not weaken scanners or add blanket exceptions.
- Every code batch requires targeted tests + formatting + PHPStan/Psalm/security + supported PHP 8.4/8.5/8.6 CI. Native and hosted acceptance must remain green when changes affect those paths. For hot paths collect repeatable before/after throughput evidence before claiming a speedup. A CI pass is not an RPM improvement.
- The existing `psalm.xml` file-scoped `TaintedSSRF` suppression in `src/Network/Dialer.php` is **an outstanding approval/review issue**, not proof the underlying sink is safe. Preserve `DialOptions::addressPolicy` candidate and connected-peer validation; do not enlarge the exception. Any change to detector settings requires FLOW-02 evidence and explicit approval.

## Baseline measurements (GitHub tree, not runtime profiling)

| Measure | Baseline | Interpretation |
| --- | ---: | --- |
| Production PHP `src/**/*.php` | 448 files | Structural diagnostic, not loaded-script count |
| Production source bytes | 1,982,567 | Not resident PHP memory |
| Internal path files | 151 | Candidate review subset, not 151 needless classes |
| Files in enum directories | 40 | Often meaningful closed-state contracts |
| Exception/error/failure-named files | 27 | Often precise catch boundaries |
| Interface-named files | 23 | Audit actual extension/substitution contracts |
| HTTP production files | 151 | HTTP/1, H2, H3 and clients; protocol separation is deliberate |
| Network, Runtime, Supervisor files | 63 / 57 / 42 | Distinct lifecycles, must preserve |
| Test PHP files | 338 | Keep QA coverage |
| PHP 8.4–8.6 reference CI | Green on starting SHA | Historical verification is invalidated by edits |

Do not present source size or file count as direct runtime gains. The small-file density quick screen requires further filtering of legitimate enums, exceptions, records, tests and boundary types before applying STRUCT-13 thresholds.

## 13-section principle map and audit backlog

| Section | Rule IDs | Source evidence / decision | Proposed verification |
| --- | --- | --- | --- |
| 01 CORE | CORE-01..02 | Framework-agnostic runtime and optional integrations. Preserve correctness and explicit boundaries above RPM. | Public API manifest, cross-protocol suite |
| 02 FLOW | FLOW-01..12 | Existing analyzers green, but approved rationale/removal condition for `TaintedSSRF` exception requires review. Run PHPForge structural checks as available. | Existing CI + focused suppression policy/security review |
| 03 PERF | PERF-01..07 | Repeated per-request middleware layer allocation and duplicate HTTP/3 preflight are concrete cost hypotheses. | Stable successful RPM + cold/warm/allocation evidence |
| 04 COMPAT | COMPAT-01..05 | PHP 8.6 matrix green; host compatibility docs retain stale 'pending' claims. | Correct evidence by host/extension; all PHP matrices |
| 05 TYPE | TYPE-01..02 | Strong typed options; safe cleanup only after validating caller constraints. | PHPStan/Psalm and negative-path tests |
| 06 STRUCT | STRUCT-01..17 | 448 files, 151 Internal; investigate single-use wrappers, methods and result types, retaining public/protocol/lifecycle types. | Explicit callers, classmap/manifest and call-graph review |
| 07 REQUEST | REQUEST-01..05 | PSR-15 chain rebuilt per request; HTTP/1 constructs stateless helpers per connection; HTTP/3 revalidates origin. | Request equivalence, multi-request/reentrant tests, benchmarks |
| 08 MEM | MEM-01..09 | QPACK records, buffered chunks, callbacks/closures and per-connection graphs are profiling candidates, not proven defects. | Peak memory, allocations, backpressure and lifecycle tests |
| 09 SEC | SEC-01..10 | Address candidate + actual connected peer enforced only with optional policy; mandated WebSocket SHA-1 is not a credential hash. | SSRF/rebinding tests, PSR exclusion decision, TLS/QUIC |
| 10 DATA | DATA-01..07 | Direct DB/ORM rules N/A to library; DNS/cache/pool reuse and upstream dialer policies apply. | DNS cancellation/fork/cache expiry, outbound timeout tests |
| 11 OBS | OBS-01..04 | Metrics and diagnostic snapshots should not eagerly allocate on unobserved hot paths. | Disabled-observer zero-regression, event/trace tests |
| 12 CONFIG | CONFIG-01..06 | PSR-4 + optimized authoritative classmap configured. File count != actual includes. | Composer validate, autoload, optional-dependency installed/absent |
| 13 OPS | OPS-01..12 | Worker, prefork, hosted runtimes, QUIC and portable modes are lifecycle boundaries. | Release Candidate, soak, hosted/native matrices, final-source SHA |

## Batch queue (ordered; commit once per batch)

### B00 — Plan and baseline [complete with plan commit]

- [x] Record the 13 sections and relevant rule IDs, baseline, hotspots, and release branch isolation.
- [x] Set rule-to-behavior-to-evidence expectations and no-BC constraints.
- [x] Create the optimization branch from PR #16's verified head. PR #16 was then closed unmerged and PR #17 retargeted to `main`; all original commits remain ancestors.
- Gate: plan file exists in the branch and can be updated along with later batches.

### B01 — Security boundary and PHP 8.6 capability truth [implemented; exact-head CI pending, P0]

- [x] Correct stale *verified vs pending* PHP 8.6 host/portable statements to match exact GitHub CI evidence, preserving optional extension limitations.
- [x] Document the dialer taint-analysis exception's explicit trust model, narrow scope, independent approval/review requirement and removal trigger without weakening any detector.
- [x] Review existing focused SSRF regression coverage for candidate and kernel-reported peer (no behavior change); tests/OutboundAddressPolicyTest.php includes direct/resolved candidates, mapped IPv6 and connected-peer mismatch.
- [x] Record affected docs and existing test coverage; branch CI must still verify documentation.
- Gate: docs/Sphinx, source audit, negative network/SSRF tests, complete required CI on this head.

### B02 — PSR-15 per-request work [implemented; exact-head QA pending, P1]

- [x] Inspect adapter and terminal behavior; verify empty, one, 32 and invalid middleware limits in regression tests.
- [x] Build immutable handler layers **once per adapter** after constructor validation. Preserve order, short-circuit, exceptions and request-specific attributes.
- [x] Add repeated-request handler identity, separate request attributes, empty and full 32-layer stack, invalid list, exception and short-circuit tests. Nested/reentrant behavior remains part of QA review.
- [ ] Run repeated cold/warm, memory/allocation and representative successful RPM comparisons. Deterministic new test establishes chain identity reuse and removes up to 32 per-request layer constructions, **not measured throughput gains**.
- Gate: focused PSR tests + full analysis/QA and parity; no native-reactor synchronous middleware.

### B03 — HTTP/1 hot connection setup and call hops [CI green; measurement pending, P1]

- [x] Inspect per-connection object lifetimes; RequestHeadValidator, ChunkSizeDecoder and Http1Syntax are stateless but shared ownership is deferred pending benchmark evidence; WebSocketUpgradeOwner is only needed for an upgrade.
- [ ] Identity-only WebSocketUpgradeOwner retain* methods were audited but kept: direct assignment triggers Rector, while promoted parameters alter the locked source signature. Revisit only with explicit migration evidence.
- [x] Instantiate WebSocketUpgradeOwner only in the actual upgrade callback while keeping session owner and handoff order identical; no cross-connection stateless-helper sharing without measurement.
- [x] Keep Http1Input ByteQueue ownership, protocol parser limits and exact error statuses intact.
- Gate: HTTP/1 malformed/slowloris/chunks, H1→WebSocket handoff, streaming/backpressure tests and RPM checks.

### B04 — HTTP/3 duplicate validation and preflight [CI green; perf pending, P1]

- [x] Inspect immutable root-origin revalidation, temporary HTTP/3 codec and static QPACK preflight; reuse the verified origin and preflight codec.
- [x] Preserve sibling origin/ALPN checks; original root and options are immutable, and preflight still executes before any native session allocation.
- [ ] Reverify all independent QUIC, GOAWAY, TLS peer, QPACK and cancellation suites on B04 exact head.
- Gate: independent HTTP/3 QUIC PHP 8.4/8.5/8.6 tests, multiplex/cancellation, security and throughput.

### B05 — Small internal type and call-chain audit [implemented; QA pending, P2]

- [x] Review actual producer/owner/caller relationships for Http3ClientGoawayState, Http3ClientPeerStreamType, Http3ClientOrigin, callback dispatcher, PreparedCommand, HostDriverFactory, RuntimeSelection and bound servers.
- [x] Inline one single-use private token-cancellation predicate in the H3 client; do not remove any class already shipped in 3.0.1. Class removals are deferred pending major-release public-contract review.
- [x] Preserve BoundServer/BoundStreamServer/BoundDatagramServer because stream/datagram listener ownership and definitions are distinct; retain GOAWAY, fragmented peer-stream and callback failure states.
- [x] Record unchanged source-file count 448 and one fewer private method in Http3OutboundClient. [ ] Measure actual loaded/compiled file count with representative autoload/OPcache scenarios in B07.
- Gate: public-api manifest, classmap, cross-protocol suites and negative cases.

### B06 — Memory, observability and ownership pass [implemented; exact-head CI pending, P2]

- [x] Review QPACK blocked-stream retry work; skip retry scans when no dynamic inserts were processed or no streams are blocked. No state transition/cancellation changes. **Allocation profiling is pending B07.**
- [x] Review metrics, DNS/cache and pool ownership paths: initialize enum counters using one pass without transient mapped-key arrays; retain DNS watcher/TTL and QPACK state ownership. Full runtime leak/throughput profiling remains B07.
- [x] Preserve QPACK encoded/decoded state records and all public and lifecycle types; zero class/file removals in B06.
- Gate: bounded-memory/soak/cancellation/fork and relevant cross-protocol tests.

### B07 — Final measurement, tracker closure, and release handoff [instrumented; QA/perf evidence pending, P0 gate]

- [x] Recount source files and interfaces against PR #16 baseline: 448 production PHP files and 23 Interface-named files unchanged; Internal paths 151 unchanged; source bytes 1,982,567 → 1,982,757 (+190). [ ] Run representative autoloaded/compiled-script profiles; GitHub tree is not a loaded-file measurement.
- [x] Add a same-process PHPBench reference-vs-optimized PSR-15 32-layer workload with identical middleware/terminal/request and separate setup measurement. [ ] Collect resulting PHP 8.4–8.6 benchmark JSON and run production-equivalent sustained RPM, p95/p99, memory and error-budget comparisons before claiming gains.
- [ ] Confirm PHP 8.4–8.6, optional extensions, docs, public API, packaging, security and final-source SHA; report any unsupported combination precisely.
- [ ] Separate completed code work from release certification, main-branch merge or tagging; **never automatically merge**.
- Gate: all required CI green on final exact SHA; blockers explicit.

## Rolling tracker — append a dated entry *below* after every batch

| Batch | Status | Commit SHA | Evidence | Residual / next |
| --- | --- | --- | --- | --- |
| B00 | Complete | `ffcde08ad75666bd3f1e874b904c2f86f5ad7e28` | 13-section source inventory and scoped tasks recorded | B01 |
| B01 | Implemented; doc CI failure | `eb1cf9f4c207b194b3508a65928b04760e2a84e6` | PHP 8.4–8.6 evidence/doc reconciliation and SSRF trust documentation; Source Audit green, RST failed on short heading underline | B01-R repairs Sphinx; approval still required |
| B01-R | CI green | `71a006e0b99b4e0a9b265f0d0f096526111d2b46` | Sphinx, Source Audit, PHPForge analysis/QA PHP 8.4–8.6, native and hosted workflows passed (conditional jobs skipped) | SSRF exception requires approval |
| B02 | QA failures (PHPCS) | `2868e66931bd8d39785923eaf71dd53d13f6a4ad` | New repeated-request tests + immutable handler reuse; Pest, Pint, Deptrac, syntax passed; PHPCS flagged 5 unused parameters in 3 test fixture methods | B02-R addresses QA warnings |
| B02-R | QA remediation | `56e29cc0ae31d572c8223fc6a31f838fb0ff9c47` | Fix PHPCS unused interface parameters in fixtures | B02-R2 resolves PHPStan mixed cast |
| B02-R2 | Implemented / CI pending | This commit | Explicitly validate PSR attribute type rather than casting mixed | Verify exact-head full PHPForge QA/analysis |
| B03 | QA remediation needed | `e7ae8ffb472a0a8aa49be5ad57978eacc82af2be` | Lazy WebSocket owner, removal of 3 retain* methods; Pest, PHPCS, Deptrac, syntax and scanner passed | Pint property order and Rector constructor promotion |
| B03-R | Source Audit rejected | `f18b5f5d2f14229b3643d87f4520d12845cabaec` | Fixed Pint and Rector, but changed an audit-locked constructor declaration | B03-R2 restores source signature |
| B03-R2 | Fully CI green | `8802a0908ccafb5f70019e29331c3ceb4a0ffb23` | Source Audit, six PHPForge QA lanes, three analysis lanes, three QUIC jobs, portable/hosted and docs | B07 perf measurement |
| B04 | All required CI green | `f39fd2385a399f61874a994542dae8cb3534802b` | 19 Security & Standards jobs passed, PHP 8.4–8.6 independent H3, six QA, three analysis, all other workflows | B07 RPM measurement |
| B05 | Analyzer regression; remediation committed | `ee51f2c4e0c0f7d6db4677da7baee07ba7802981` | All QA/other workflows passing or executing; PHPStan/Psalm flag 1 non-null token nullsafe at Http3OutboundClient:656 | B05-R fixes narrowing |
| B05-R | Implemented / exact-head CI pending | This commit | Replace redundant nullsafe on already validated cancellation token | Full PHPStan/Psalm PHP 8.4–8.6, QA and H3 CI |
| B06 | Implemented / exact-head CI pending | This commit | Skip QPACK retry when no dynamic insertion; reduce temporary allocations initializing metrics; add regressions | Full PHPForge QA/analysis, H3 and B07 measurement |
| B07 | Benchmark added / CI evidence pending | This commit | Same-runner prebuilt-vs-reconstructed PSR-15 reference, 448 src PHP and 151 Internal unchanged; 23 Interface-named PHP files | Examine JSON benchmark artifacts, final source SHA and exact-head full CI; sustained RPM remains unmeasured |

### B00 — 2026-11-21

- Source snapshot: 448 production PHP files and 338 test PHP files from PR #16 head `f1c99ba`; PHPForge principle reference `c1f68be`.
- Conclusive hot-path finding: `PsrMiddlewareAdapter::handle()` constructs a bounded chain repeatedly (up to 32 middleware per request).
- Structural review candidates require caller-graph proof; no production class has been approved for deletion merely by size.
- Verification status: historical original head passed required CI; this plan-only commit has not independently established fresh CI. No performance improvement has been measured.

### B01 — 2026-11-21

- Changes: `docs/host-compatibility-3.0.rst` and `docs/compatibility-audit-3.0.rst` now separate current PHP 8.6 hosted/native acceptance from the historical PHP 8.4/8.5 2.1.2-versus-3.0 comparison; optional extension coverage is scoped.
- Security: `docs/security-handbook-3.0.rst` now explains the low-level dialer's intentional private-service capability, required authorization of untrusted destinations, file-scoped Psalm exception and review/removal trigger. No detector or runtime behavior changed.
- Existing focused tests inspected: `tests/OutboundAddressPolicyTest.php` covers direct/resolved candidates, special-use/mapped IPv6, connected-peer mismatch and zero watchers after rejection. These tests were **not rerun by the GitHub source-edit operation**.
- Prior verified source: PR #16 `f1c99ba`: RoadRunner, FrankenPHP and FPM on PHP 8.4/8.5/8.6; Portable Native and QUIC all green. New PR #17 commit requires fresh CI.
- **Outstanding decision:** FLOW-02 calls for explicit engineering approval of the existing SSRF file exception. Documentation is not approval. Do not claim the release security review closed without sign-off.

### B01-R — 2026-11-21 (one documentation-remediation commit)

- [x] PR #16 closed without merging; PR #17 retargeted from the former PR #16 branch to `main`. Ancestry check: PR #17 had 25 commits ahead of `main` and 52 changed files, including every PR #16 change and this plan, with none behind `main`.
- [x] Fixed Sphinx warning-as-error in `docs/security-handbook-3.0.rst`: title underline shorter than title in B01.
- [x] Retained original `fix/worker-fatal-diagnostics-and-streamwriter-notices` branch for historical traceability; do not delete or merge automatically.
- [ ] Exact-head docs/source audit and required PR checks must pass after this commit. Previous green checks refer to PR #16 SHA, not the consolidated latest SHA.
- [ ] Explicit security sign-off for file-scoped dialer `TaintedSSRF` exception remains an approval gate.

### B02 — 2026-11-21

- Changed `src/Interop/Psr/PsrMiddlewareAdapter.php`: bounded middleware list is validated before building an immutable chain in the constructor, instead of allocating a fresh chain inside `handle()` for every request. The public constructor names and PSR interfaces remain unchanged.
- Extended `tests/PsrMiddlewareAdapterTest.php`: the same next-handler identity persists across calls while attributes differ, zero/32 elements work, invalid entries fail, and short-circuit/throw behavior stays intact.
- Structural outcome: no production files removed; no unsafe protocol/lifecycle fusion. Repeated calls no longer instantiate layer objects; setup occurs once per adapter. **No measured throughput/allocation delta yet**; B07 must benchmark it against the pinned baseline.
- Previous B01-R code at `71a006e` passed all required workflows; B02's exact-head gates must be verified before closing this batch.

### B02-R — 2026-11-21

- CI feedback on B02 `2868e66`: PHPForge Pest, Pint, Deptrac, syntax, reference integrity and scanner all passed; PHPCS failed on five unused required PSR-15 interface parameters in new test fixtures (three lines).
- This one-commit remediation makes the terminal and middleware fixture parameters behaviorally meaningful: request methods influence responses/exception messages and the original next-handler reference is asserted in short-circuit and exceptional paths.
- No changes to runtime production code, public API or lifecycle. Do not count B02 as green until exact-head PHPForge analysis/QA passes. Throughput remains unmeasured.

### B02-R2 — 2026-11-21

- Historical B02 analysis on `2868e66` exposed PHPStan `cast.string` in the new PSR test: `ServerRequestInterface::getAttribute()` returns mixed.
- This one-commit remediation checks `is_string` before producing a response header; invalid test setup raises an explicit runtime exception. No production/runtime changes.
- B02-R already corrected PHPCS unused PSR interface parameter warnings. Complete B02 QA only after the new exact-head run is green; any earlier run's diagnostic is not final proof.

### B03 — 2026-11-21

- `Http1Connection` retains no `WebSocketUpgradeOwner` on ordinary HTTP/1 requests; the owner is constructed when the response writer's WebSocket upgrade callback is invoked, before any 101 handoff, and remains connection-owned thereafter. `pump()` and `drain()` handle the absent owner without invoking new callbacks.
- Audited 3 identity-only `WebSocketUpgradeOwner::retain*` methods. Their removal was reverted in B03-R2: Rector requires promotion for direct assignment, but promotion changes the audited constructor declaration. No named public or protocol-state type was removed.
- Kept ByteQueue/input ownership, parser helper behavior, callback ordering, and existing WebSocket 101 handoff unchanged. **Performance hypothesis:** one helper object + handoff closure avoided for ordinary non-upgrading HTTP/1 connections; effect on successful RPM is not yet benchmarked.
- Gate: full HTTP/1/WebSocket and hosted/native suite + PHPForge static analysis; benchmark in B07. Source changes are not independently verified until exact-head CI completes.

### B03-R — 2026-11-21

- PHPForge B03 QA reported `ordered_class_elements` for the nullable `Http1Connection::webSocketOwner` property and `ClassPropertyAssignToConstructorPromotionRector` for three identity assignments in `WebSocketUpgradeOwner`.
- Moved only the nullable property to its sorted group. Promoted `LoopInterface`, `Connection` and `Http1Input` to readonly constructor properties. Positional construction and ownership semantics are preserved; the internal class's constructor argument names now reflect the owned fields.
- Previous B03 Pest, PHPCS, Deptrac, scanner, syntax passed; Pint and Rector required remediation. This commit carries **no new functional path**; exact-head CI must still pass before B03 is closed.

### B03-R2 — 2026-11-21

- Source Audit on B03-R `f18b5f5` rejected an unreviewed method declaration change after Rector-style constructor promotion. Preserving positional invocation was insufficient; the migration ledger tracks source-level signatures.
- Restored `src/Http/Http1/Internal/WebSocketUpgradeOwner.php` exactly from the pre-B03 head `fe7be6e`, including the original constructor argument names and three identity helpers. This deliberately keeps a few tiny hops rather than weakening the locked public-source contract.
- Preserved the useful B03 optimization: lazy owner creation for actual WebSocket upgrades only, and correctly sorted nullable property in `Http1Connection`.
- This exact-head commit needs Source Audit and PHPForge QA; there is still no measured throughput/file-load gain. No API bypass was added.

### B04 — 2026-11-21

- `ClientRequest` and `QuicClientOptions` are final readonly. `Http3ClientOrigin::validate()` still validates **each sibling** against the verified TLS peer and `h3`-only policy; the immutable original origin array is saved for comparison, eliminating redundant root parsing.
- The final `Http3ClientWireCodec` is constructed before native QUIC I/O, performs the existing unsafe-framing preflight, then is owned by the client. Outbound QPACK uses zero dynamic capacity, so no dynamic reference state survives preflight; a new regression compares repeated encoding against a fresh codec with a distinct stream ID.
- No public API or protocol-state classes removed. Expect one fewer temporary codec instance per root session and one fewer immutable root-origin parse per sibling, **not measured throughput gains**.
- B03-R2 head `8802a09` passed all required Security & Standards jobs and native/host/docs workflows. B04 exact-head QA and H3 interop are pending.

### B05 — 2026-11-21

- Compatibility baseline: PR #17 starts with 448 production PHP source files; all six major candidates (`Http3ClientGoawayState`, `Http3ClientPeerStreamType`, `ConnectionCallbackDispatcher`, `PreparedCommand`, `HostDriverFactory`, `RuntimeSelection`) **already shipped in tag 3.0.1**. Deleting an autoloadable class for a 3.0.2 patch is not justified by its size or `@internal` annotation alone.
- Ownership/callers: peer-stream varint parser belongs to registry, but its independent fragmented/empty/payload tests and published class mean a deletion would cost BC. GOAWAY owns rejection cutoff; callbacks preserve first failure after deterministic cleanup; prepared commands preserve argument/environment types; host factory maps lifecycle-owned runtime drivers; RuntimeSelection carries driver, capabilities and warnings. BoundServer/BoundStreamServer/BoundDatagramServer reflect independent listener contracts.
- Safe code change: `Http3OutboundClient::tokenCancelled` had exactly one caller and only forwarded a nullable token check. Inlined the identical expression into `start()` and removed the private method (one fewer method/call hop, zero class removals). Root cancellation/error behavior remains the same.
- Structural counts: 448 production PHP files before and after B05; no added or removed public classes, enums or interfaces. **Actual per-request included-file count, OPcache bytecode and throughput have not been measured**; B07 remains responsible.
- Do not refactor types solely to hit a deletion quota. If class removal is still desired, explicitly scope a major-version migration with public API diff and consumer impact first.
- B04 head `f39fd23` passed all 19 Security & Standards jobs and all required workflows. B05 exact-head quality/source/cross-protocol checks are pending.

### B05-R — 2026-11-21

- B05 head `ee51f2c` failed PHPStan `nullsafe.neverNull` (1) and Psalm `TypeDoesNotContainNull`/`RedundantCondition` (2) at `Http3OutboundClient.php:656` in the cancellation-true branch. Token existence follows from `$token?->isCancelled() === true`.
- Single narrow correction: use `$token->reason()` instead of `$token?->reason()` inside the guarded branch; leave fallback `CancellationReason::HOST_CANCELLED`, cancellation ordering, deadlines, and other null-safe access unchanged.
- No files/types added or deleted; no behavior/contract change. B05-R analyzer and all required exact-head jobs must run before marking green.

### B06 — 2026-11-21

- B05-R fix on `e3c23a0` passed docs/source/host/native preliminary workflows; full PHPForge analysis/QA and QUIC were still running when B06 was prepared. Do not mark B05-R fully green until exact-head checks complete.
- QPACK: `Decoder::pushEncoderInstructions()` now avoids `retryBlocked()` when the encoder stream produced zero dynamic insertions or no sections are blocked. This prevents useless blocked-state scans and avoids allocating a `ReadyFieldSection` list for an unproductive retry; insert-bearing instructions still retry in original stream order.
- Metrics: replace `array_map` plus `array_fill_keys` temporary key arrays for `RuntimeMetrics` enum counters with direct ordered `foreach` initialization; snapshots and error/protocol key order are unchanged.
- Added regression assertions in `tests/QpackTest.php` and `tests/MetricsDiagnosticsTest.php` for QPACK empty update/no duplicate ready callback and independent zeroed counters matching enum case order.
- Reviewed but deliberately retained DNS waiter coalescing, cleanup, cancellation/fork protections, and QPACK blocked queues. No rewrites of stateful streaming/data ownership without workload profiling.
- **Not measured:** real memory allocations, sustained RPM, or QPACK full benchmark delta. B07 must collect matched evidence before claiming a performance improvement.

### B07 — 2026-11-21 (instrumentation batch)

- Source-tree recount at B06 `a8a3b88`: production PHP 448 → 448, Internal paths 151 → 151, Interface-named paths 23 → 23; total source bytes 1,982,567 → 1,982,757. No new or deleted production class/file; fewer redundant hot-path constructions and one fewer private method.
- New PHPBench `benchmarks/StructuralWindowBench.php` compares three subjects using the exact same 32 middleware objects, request and terminal: `benchPsrPrebuiltHandle`, `benchPsrReferenceRebuild` (pre-B02 implementation loop reconstructed), and `benchPsrPrebuiltConstruction`. It intentionally separates cold adapter setup from repeated handling.
- Use PHPForge existing `phpforge / Benchmark` matrix and its raw JSON artifacts. Compare within the **same** job and PHP version; never compare absolute times across unrelated hosts/commits as evidence of throughput. Any measured ratio is a synchronous PSR-15 microbenchmark, not sustained HTTP RPM, memory or end-to-end latency.
- Source instrumentation itself adds one **non-production** benchmark class/file. No production type removal is justified on 3.0.2: published state/driver type contracts remain intact.
- **Pending:** exact-head benchmark output and quality CI, representative loaded-script/OPcache measures, matched production-like sustained successful RPM/p95/p99, and FLOW-02 explicit owner review for the existing dialer SSRF exception. Keep the draft PR open and unreleased.
