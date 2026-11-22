# Runwire — PHPForge 13-section engineering and structural optimization plan

> **Archive location:** This complete plan/tracker moved from `docs/plans/` to `qa/release-baseline/` to satisfy the existing immutable release-certification preflight. The audit history and unresolved sign-off gates are retained; it is not a release approval.

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
- B07-S2 removes the root `psalm.xml` override, including its file-scoped `TaintedSSRF` suppression for `src/Network/Dialer.php`. Standard PHPForge analysis uses `vendor/infocyph/phpforge/resources/psalm.xml` when neither root `psalm.xml` nor `psalm.xml.dist` exists; it treats `TaintedSSRF` as an error. Preserve `DialOptions::addressPolicy` candidate and connected-peer validation; resolve any newly exposed finding at its root cause without configuration overrides or suppression.

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

### B08 — QUIC worker poll-array copy elimination [planned, P1]

- [x] Recheck PHP 8.4–8.6 default-configuration Psalm from B07-S2: exactly one SSRF taint failure traced from PSR request URI to generic dialer; tracked as P0 security blocker without suppression.
- [x] In `PhpQuicHttp3Worker::tick()`, remove an avoidable `array_values($connections)` copy; teach `PhpQuicHttp3Poller`'s *docblock* to accept sparse integer keys (its iteration already ignores keys). Preserve existing public method parameters and behavior.
- [x] Replace nested `array_map(..., array_values($pendingConnections))` with one bounded `foreach` conversion, preserving poll order, object identity and masks; do not change poll callback result IDs or handshakes.
- [x] Add focused multi-active-connection regression exercising sparse retained IDs after one connection disappears. [ ] Require all PHP 8.4–8.6 source/analysis/QA, independent QUIC and hosted checks to pass on exact SHA.
- Gate: behavior and error-path equivalence; no unsupported throughput claim.

### B09 — StreamWriter per-write handler allocation [planned, P1]

- [x] Source hot-path audit: `Connection` calls `StreamWriter::write` from both immediate writes and flush loops; previous `self::writeWarning(...)` constructs a first-class Closure per call. Runtime microprofile and sustained RPM delta remain pending.
- [x] Reuse a statically held handler closure *without* changing the per-call `set_error_handler`/`finally restore_error_handler` safety contract, notices/warnings, partial writes, nesting, or callback semantics.
- [x] Extend repeated valid writes and external handler restoration test; existing invalid/closed resource notice tests remain. [ ] Run PHPForge QA, native/hosted lifecycle and supported PHP matrix.
- Gate: benchmark setup and measured workload delta clearly distinguish closure allocation from actual request RPM.

### B10 — Class/method consolidation and compatibility disposition [planned, P2]

- [x] Simplify `LifecycleEmitter` startup counter initialization to one `foreach`, matching the B06 `RuntimeMetrics` pattern; test enum order and failure counters.
- [x] Review single-owner helpers `AdaptivePollStrategy`, `Http1PolicyAttempt`, `QuicWorkerResourceUsage`, `ConnectionCallbackDispatcher`, `ContentLengthParser`, `WorkerStopState` with actual callers/tests. [ ] First-load profile on final candidate.
- [x] Do **not** delete or merge released PHP symbols merely to lower count. All 448 production paths in PR #17 already existed in `3.0.1`; record legitimate class/file removals requiring a major release instead of silently breaking patch compatibility.
- [x] Check wrapper/helper methods for delegation without invariant, preserving exception/owner/lifecycle behavior; decisions and retained wrappers recorded below.
- Gate: PHP 8.4–8.6 QA/analysis, public API integrity, all protocols; explicit 448-file count and call-hop outcome.

### B11-C1 — Connection callback call-hop reduction [planned, P1]

- [ ] Inline the `Connection::invoke()` delegation: **rejected/deferred** after PHPStan found class cognitive complexity 84, limit 80. Restore previously verified dispatcher, no complexity exception or threshold change.
- [x] Add focused null callback, original throwable, and connection termination regression.
- [ ] Confirm supported PHP 8.4–8.6 QA/analysis and native/hosted exact-head workflow status without hiding the existing SSRF finding.
- Gate: callback ordering, exactly-once abort, cleanup, exception propagation.

### B11 — Measured structural completion and certification [historical results only; final SHA pending, P0]

- [x] Reconcile historical B07-S1 matched source/OPcache and sustained HTTP/1 evidence from release certification `44927412407`. [ ] Repeat these tests on the final B08/B09/B10 source **after** unsuppressed Psalm and security validation are green.
- [ ] Evaluate a repeated warm runtime and cold bootstrap separately; do not interpret reduced source lines or helper count as production speedup.
- [ ] Reconcile every open task, CI result and artifact back into the same plan/tracker; mark `done` only for implemented and verified requirements, with explicit `deferred` for evidence-based major-only changes.
- [ ] Keep PHPForge's default Psalm configuration untouched; do not merge/tag/release without exact-head green gates and final security disposition.
- Gate: full current-source CI and release performance evidence, with no unverified claims.

## Rolling tracker — append a dated entry *below* after every batch

| Batch | Status | Commit SHA | Evidence | Residual / next |
| --- | --- | --- | --- | --- |
| B00 | Complete | `ffcde08ad75666bd3f1e874b904c2f86f5ad7e28` | 13-section source inventory and scoped tasks recorded | B01 |
| B01 | Implemented; doc CI failure | `eb1cf9f4c207b194b3508a65928b04760e2a84e6` | PHP 8.4–8.6 evidence/doc reconciliation and SSRF trust documentation; Source Audit green, RST failed on short heading underline | B01-R repairs Sphinx; approval still required |
| B01-R | CI green | `71a006e0b99b4e0a9b265f0d0f096526111d2b46` | Sphinx, Source Audit, PHPForge analysis/QA PHP 8.4–8.6, native and hosted workflows passed (conditional jobs skipped) | SSRF exception requires approval |
| B02 | QA failures (PHPCS) | `2868e66931bd8d39785923eaf71dd53d13f6a4ad` | New repeated-request tests + immutable handler reuse; Pest, Pint, Deptrac, syntax passed; PHPCS flagged 5 unused parameters in 3 test fixture methods | B02-R addresses QA warnings |
| B02-R | QA remediation | `56e29cc0ae31d572c8223fc6a31f838fb0ff9c47` | Fix PHPCS unused interface parameters in fixtures | B02-R2 resolves PHPStan mixed cast |
| B02-R2 | Implemented; later superseded by green checks | `fe7be6e09ffb` | Explicitly validate PSR attribute type rather than casting mixed | Verify exact-head full PHPForge QA/analysis |
| B03 | QA remediation needed | `e7ae8ffb472a0a8aa49be5ad57978eacc82af2be` | Lazy WebSocket owner, removal of 3 retain* methods; Pest, PHPCS, Deptrac, syntax and scanner passed | Pint property order and Rector constructor promotion |
| B03-R | Source Audit rejected | `f18b5f5d2f14229b3643d87f4520d12845cabaec` | Fixed Pint and Rector, but changed an audit-locked constructor declaration | B03-R2 restores source signature |
| B03-R2 | Fully CI green | `8802a0908ccafb5f70019e29331c3ceb4a0ffb23` | Source Audit, six PHPForge QA lanes, three analysis lanes, three QUIC jobs, portable/hosted and docs | B07 perf measurement |
| B04 | All required CI green | `f39fd2385a399f61874a994542dae8cb3534802b` | 19 Security & Standards jobs passed, PHP 8.4–8.6 independent H3, six QA, three analysis, all other workflows | B07 RPM measurement |
| B05 | Analyzer regression; remediation committed | `ee51f2c4e0c0f7d6db4677da7baee07ba7802981` | All QA/other workflows passing or executing; PHPStan/Psalm flag 1 non-null token nullsafe at Http3OutboundClient:656 | B05-R fixes narrowing |
| B05-R | Implemented; later validated in B06 | `e3c23a04f93e` | Replace redundant nullsafe on already validated cancellation token | Full PHPStan/Psalm PHP 8.4–8.6, QA and H3 CI |
| B06 | Complete; eight required workflows green | `a8a3b88abc4b` | Skip QPACK retry when no dynamic insertion; reduce temporary allocations initializing metrics; add regressions | Full PHPForge QA/analysis, H3 and B07 measurement |
| B07 | Instrumentation only / full CI not triggered | `af16478b143fab33932f1369119113c1084edabe` | New PHPBench subjects for reference vs prebuilt PSR; no production source changes, CodeQL passed | B07-R smoke test and full QA |
| B07-R | CI blocked; remediation queued | `3dd0eca8b51b` | Test equal PSR responses for all three benchmark subjects plus repeat handle; includes test-path change for QA trigger | Review three-version benchmark JSON and full checks |
| B07-R2 | QA/analysis follow-up required | `66bce1f81b60993932d1faa8fcdd62f1aff3f1a1` | Namespace benchmark under Infocyph for legal internal access; initialize in constructor; order members, annotate exceptions, prove middleware element types | Confirm PHP 8.4–8.6 Psalm/PHPStan, Pint, full QA and QUIC; do not declare green before checks |
| B07-R3 | Complete; eight required workflows green | `719f82ca56954e609a6e6d74f0e663912b9d344b` | Remove extra class-close separator; put benchmark smoke test in the Infocyph test namespace to satisfy internal API boundary | Validate PHP 8.4–8.6 QA/analysis, all workflows; no release/throughput claim |
| B07-P1 | Complete implementation; eight required workflows green (opt-in evidence pending) | `a2d2ff14a00a646d05e34844aabfc6f64b99a303` | Capture six cold PHP/OPcache profiles (baseline/candidate x bootstrap/lifecycle/coroutine) on one PHP 8.6 runner; record counts and source identity | B07-P2 evaluate matched structural profiles and sustained successful RPM, errors/latency; no speed claim from file counts |
| B07-S1 | Complete; eight required workflows green, immutable preflight and package PHP 8.4–8.6 passed | `5d3a2cabc0ce1dde3509e051a75a23dd15b639d7` | Preserve audit plan and tracker under qa/release-baseline instead of docs/plans; maintain release preflight contract | Validate all required workflows; opt-in perf and security sign-off still pending |
| B07-S2 | Exact-head Psalm failed: 1 `TaintedSSRF` (PHP 8.4–8.6), other gates green | `12451992e75e6f5cbb7a7bad032269aa87ebf7be` | Remove root `psalm.xml` without replacement, override or suppression; PHPForge's default Psalm config is authoritative | Verify PHP 8.4–8.6 Psalm with unsuppressed TaintedSSRF, full CI; address real findings in code, then re-certify frozen SHA |
| B08-P | Plan and issue log committed; unsuppressed Psalm identified one SSRF path | `7ff65b501e439accf561084605a91de018a1cf96` | B08 QUIC polling, B09 write path, B10 class/method compatibility, B11 measured acceptance | Work in one-commit batches with exact-head QA and no automatic merge/tag |
| B08 | Runtime/QUIC/QA green; only pre-existing Psalm SSRF red across PHP 8.4–8.6 | `a641b0bbfaf597fd1451647a99fdafc6f2561f44` | Direct foreach for pending, no array_values for sparse active IDs, retain poll semantics; add multi-connection disappearance test | PHPForge default Psalm SSRF remains blocker; run QUIC PHP 8.4–8.6 and all required QA |
| B09 | Stream write caching committed; exact-head QA pending | `2aa3e13acdf1208dd9738f98eca907d18d96ea71` | Reuse a cached warning handler closure on every fwrite without changing set/restore boundaries; add repeated-write/restoration test | Need benchmark evidence, full QA + resolve default Psalm SSRF separately |
| B10 | Supervisor counters implemented; exact-head QA pending | `59679f30add36ab7de11ca7a82feec50099d8e92` | Direct ordered foreach; test failure count and event order; avoid breaking class/file deletions | B11 performance evidence; PHPForge default Psalm security blocker still open |
| B11-C1 | Callback inlining rejected after PHPStan complexity failure | `90f96c7e1ff9738161aba7b113428efd952a9b9f` | Remove per-callback abort Closure and static dispatcher call in Connection; preserve original Throwable | Review callback termination, native/hosted, PHP 8.4–8.6; SSRF blocker remains |
| B11-C1-R | Strict Source Audit green; PHPStan complexity issue found | `9345ac57cb045718d149ef3c2c72e3ef6413595d` | Add reviewed LifecycleEmitter migration behavior with executable SupervisorLifecycleTest coverage, keep strict source audit unchanged | Verify strict Source Audit and all QA; unsuppressed Psalm SSRF still open |
| B11-C1-R2 | Excess callback inlining reverted; exact-head QA pending | `f3eacd05fdd49f9f80709cc50bc2b1e5d81d02f1` | Restore verified Connection callback dispatch to meet strict complexity limit 80; retain exception regression | PHPStan/QA/native/hosted + default Psalm must be checked; SSRF still open |
| B11-M | Historical performance and OPcache evidence reconciled; final-source measurement blocked | `0584e26e22b8ebac0591a262564c1eb660f20fd7` | Cert run 44927412407: older source -1.273% H1 RPS, unchanged loaded script counts; 30m soak and guard passed | Re-run after secure SSRF fix and final exact-head QA; no release |
| B12-S1 | PSR URI approval implemented; Sphinx example failed, PHPForge analysis pending | `14dbad1eb72c9fbaff64d3755363a189c948f78a` | Require exact caller-approved URI from trusted config, use approved string for outbound ClientRequest, reject substitutions, add PSR security tests and docs | PHPForge default Psalm/PHPStan PHP 8.4–8.6; full CI including Source Audit; inspect compatibility implications |
| B12-S1-D | Sphinx example syntax repaired; exact-head QA pending | This batch commit; SHA backfill next tracker batch | Correct doubled PHP namespace separators in example and inline literal; retain strict validator | Confirm PHP 8.4–8.6 analysis, full QA, native/hosted and Sphinx |

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
- [x] Remove the root file-scoped dialer `TaintedSSRF` override in B07-S2; no substitute local Psalm config. [ ] Prove full PHPForge default Psalm/taint analysis passes without the exception and complete independent outbound trust-boundary review.

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

### B07-R — 2026-11-21

- Previous B06 `a8a3b88` is fully CI green across all eight required workflows, including three PHPStan/Psalm, six PHPForge QA, PHP 8.4–8.6 independent H3/QUIC, hosted, Portable Native and Swoole/OpenSwoole. This does **not** certify later B07 commits.
- B07 `af16478` added a PHPBench-only workload and tracker. CodeQL passed, but path-filtered required PR workflows were not triggered by benchmark/plan-only changes.
- B07-R adds `tests/StructuralWindowBenchmarkTest.php`, verifying that all three benchmark subjects return the same 204 status and that a repeated prebuilt handle stays correct. This test-path change is intended to trigger the standard QA matrix without adding a custom benchmark workflow.
- No performance result is asserted by the test. Only compare same-runner benchmark subjects after their exact-head JSON artifacts exist; still require matched production-equivalent successful RPM/p95/p99 and load/OPcache profiling for release-performance claims.

### B07-R2 — 2026-11-21

- B07-R head `3dd0eca8b51b` reported one Pint `ordered_class_elements` violation in `benchmarks/StructuralWindowBench.php` across six QA combinations. PHP 8.6 Psalm also reported uninitialized benchmark properties, missing throw docs and access to internal PSR-15 code from the global namespace. This batch fixes those causes without disabling rules.
- Benchmark fixture now initializes required properties in its real constructor (rather than relying on PHPBench `BeforeMethods` to satisfy static analysis), moves under `Infocyph\\Runwire\\Benchmarks` to preserve `@internal` access boundaries, orders methods deterministically, and documents expected exceptions; equivalence test uses its new namespace and initialization contract.
- Production PSR-15 adapter: build a bounded `list<MiddlewareInterface>` while validating, then construct the immutable handler chain from that verified list. This resolves the outstanding `MixedArgument` review root cause without altering named constructor parameters, middleware order, or repeated-request behavior. This introduces only an adapter-construction-time bounded reference list (maximum 32), not per-request work.
- B07-R PHPBench jobs produced data for PHP 8.4–8.6, but runner used 10 revs/3 iterations despite source attributes requesting 1000 revs/5 iterations. The reported relative ratios are diagnostic only; B07-P must review effective runner configuration, repeatability and steady-state RPM before certification.
- **Validation pending:** new exact-head 8.4/8.5/8.6 analysis/QA, H3 QUIC, full required workflows. Do not mark B07-R2 green before checks; do not merge/tag. Git commit SHA for this same-commit tracker row must be backfilled from branch history at the next batch.

### B07-R3 — 2026-11-21

- B07-R2 `66bce1f81b60` retained one Pint `class_attributes_separation` failure in `benchmarks/StructuralWindowBench.php`, caused by an extra separator before the final class closing brace. The exact-head PHP 8.6 Psalm analysis reported six `InternalClass`/`InternalMethod` findings in `tests/StructuralWindowBenchmarkTest.php`: the smoke test was in the global namespace while the benchmark is internal to `Infocyph`.
- Fixes: remove the redundant separator; add the existing `Infocyph\\Runwire\\Tests` namespace to the smoke test, matching library test-suite conventions and keeping production/internal visibility unchanged. No source changes to runtime protocol behavior or benchmark scenarios.
- Gate: PHPForge Pint/Psalm/PHPStan/Pest on PHP 8.4–8.6 and all eight exact-head workflows; if green, advance to measurement B07-P. No scanner exceptions, merge, tag or release.

### B07-P1 — 2026-11-21

- The existing opt-in `release-certification.yml` already executes a proper matched five-trial sustained successful-RPM comparison between published 3.0 and candidate, using pinned distinct physical cores and five 180-second trials. Rather than adding another workflow or an invented absolute performance result, reuse that certification.
- Add a **separate pinned structural baseline** `f1c99ba83140bf9829406c600296ebe1a78be491` (pre-B00/PR #16 certified head) alongside the published release baseline. Same job and PHP 8.6/OPcache environment profile `bootstrap`, `lifecycle`, `coroutine` from each structural-baseline/candidate checkout via the existing `benchmarks/opcache_footprint.php`.
- Write raw JSON for all six runs plus a compact comparison with exact commit identities into the already uploaded matched-performance artifact. Ensure valid output, successful request execution, enabled OPcache, nonzero included/compiled script counts and byte usage. The comparison is **cold, isolated CLI process** evidence; it does not prove request RPM or warm worker allocations.
- No production classes/files changed; PHPForge quick benchmark's 10 revs/3 iterations remains a microbenchmark and is not confused with the opt-in matched 180-second sustained test. This batch does not run opt-in release certification while the plan is still in `docs/plans` (release preflight deliberately forbids unremoved plan directories).
- **Pending:** new exact-head QA, structural comparison artifact and release certification. Finish B07-R3 QA before landing this batch; continue B07-P2 only after evidence exists. Keep draft, do not merge/tag.

### B07-R3 exact-head QA closure — 2026-11-21

- Exact commit `719f82ca56954e609a6e6d74f0e663912b9d344b` passed all eight required workflow families: Source Audit, Documentation, Release Candidate, Portable Native, Real Hosted, Swoole/OpenSwoole, HTTP/2 independent reference, Security & Standards.
- Security & Standards: all PHP 8.4–8.6 PHPForge QA matrix lanes, three PHPStan/Psalm analysis jobs, three benchmark jobs, all three independent HTTP/3 + QUIC runs passed. No failing required jobs (release-on-tag correctly skipped).
- This certifies the **B07-R3** source only, not B07-P1 or final release. Performance and SSRF sign-off remain separate.

### B07-S1 — 2026-11-21

- Preserve the full B00–B07 audit, dated history and live tracker in the non-packaged `qa/release-baseline/` archive while removing the sole file in `docs/plans`. This satisfies the pre-existing `release-certification.yml` preflight rule `test ! -d docs/plans` without weakening its release guard or erasing evidence.
- `qa` is already excluded from the Composer release package; no production class, autoload configuration, or protocol file changes. PR summary links must point at the new ledger location after this batch is pushed.
- This archival step is **not** release certification. B07-P1 exact-head QA, opt-in matched sustained RPM + structural file-load profiles, and independent engineering approval of the dialer SSRF taint exception are still required before release-ready status.

### B07-P1 exact-head QA closure — 2026-11-21

- Exact commit `a2d2ff14a00a646d05e34844aabfc6f64b99a303` passed all eight required workflow families, including six PHPForge QA lanes, three PHPStan/Psalm analyses, three PHPBench jobs and three independent HTTP/3/QUIC PHP 8.4–8.6 jobs. No required CI failures; release-on-tag appropriately skipped.
- This verifies the *instrumentation commit* only. The opt-in release-certification workflow has not yet been triggered; its new cold CLI/OPcache comparison JSON, matched sustained RPM, and 30-minute soak data are not available. Keep B07-P2 evidence gate open.

### B07-S2 — 2026-11-21 (Psalm override removal)

- User directive: remove the root `psalm.xml` outright; **no overriding**, no replacement `psalm.xml.dist`, no modified PHPForge defaults and no inline taint suppressions. Remove only the root config plus this live tracker entry in a single commit.
- Independently inspected PHPForge's reusable Security & Standards workflow: its Psalm selection is `psalm.xml`, else `psalm.xml.dist`, else `vendor/infocyph/phpforge/resources/psalm.xml`. Neither alternative root filename exists in the current branch. PHPForge's default `psalm.xml` explicitly enforces `TaintedSSRF` at error severity; removing Runwire's exception may expose a previously hidden issue in `src/Network/Dialer.php` or its callers.
- Keep source behavior and `DialOptions::addressPolicy` candidate/connected-peer safeguards unchanged in this removal batch. **Any real taint/error findings must be fixed at source in subsequent one-commit batches**, without new suppressions or excluding a file. Previously green CI/certification at `5d3a2cabc0ce1dde3509e051a75a23dd15b639d7` remains historical only for the old configuration.
- **Pending gates:** all required workflows and Psalm/PHPStan with PHP 8.4/8.5/8.6 on this exact new SHA; new immutable release certification (matched RPM, 30-minute soak, PHPForge release guard) after final code/config is green; review of intended caller-controlled vs user-controlled outbound addresses. Do not merge, tag, or publish automatically.

### B08-P — 2026-11-21 (structural continuation planning)

- Rechecked the current branch against released tag `3.0.1`: **448 source PHP paths on both**; zero production source file removals. 121 files are below 1 KiB, predominantly enums, exception types and immutable records. Treat all autoloadable class removal as public/consumer migration risk in this patch window. No arbitrary deletion quota.
- Inspected `PhpQuicHttp3Worker::tick()` and `PhpQuicHttp3Poller::poll()/buildPollItems()`: two allocations to normalize active/pending objects before an iteration that does not use caller keys. B08 targets the real copies, not unrelated class count.
- Reviewed the `Connection` write/flush paths and `StreamWriter`'s per-call first-class callable; B09 preserves the existing notice/warning boundary and only considers reuse after targeted evidence.
- Found `LifecycleEmitter` repeats the `array_fill_keys(array_map(...))` startup pattern removed earlier from `RuntimeMetrics`; B10 will simplify it and document class ownership decisions.
- B11 remains a measurement/security gate; original B07 certification is bound to the **older** SHA `5d3a2ca` and cannot certify the root Psalm deletion or later hot-path changes. Do not equate static files count with autoloads/OPcache.
- Each batch updates this plan's rolling tracker in the **same atomic commit**, and records the real SHA in the following tracker update because a commit cannot contain its own final hash.

### B07-S2 exact-head CI result — 2026-11-21

- GitHub Security & Standards `44928605757` passed PHPForge QA, benchmarks, release clean install, native QUIC; **all three PHP 8.4/8.5/8.6 analysis jobs failed on exactly one unsuppressed Psalm `TaintedSSRF` in `Dialer.php:336`**, while other required workflow families passed.
- Reproduced taint path: PSR `RequestInterface::getUri()` → `PsrMessageBridge::outboundRequest` → `ClientRequest::uri` → `Http2OutboundClient::connect` → `HappyEyeballsDialer` → `HappyEyeballsAttempt` → `Dialer::open` → `stream_socket_client`. This is a real untrusted-origin authorization issue; parsed hostname syntax alone does not prove public-IP policy. No root config reinstatement, suppression, or fabricated approval.
- Security resolution must establish explicit authorization on untrusted-origin paths, validated resolved candidate IP before dial and kernel-connected peer before handoff. Record the contract tests and avoid breaking intentional caller-owned local/private dial semantics. Keep this separate blocking gate while structural work continues; do not mark release ready.

### B08 — 2026-11-21

- `PhpQuicHttp3Worker::tick` no longer materializes `array_values($this->connections)` every tick; `PhpQuicHttp3Poller` already iterates input values and indexes readiness by QUIC object identity rather than array key. Its static doc contract now accurately permits sparse `array<int, PhpQuicHttp3Connection>`.
- Replace two separate temporary arrays (`array_values` followed by `array_map`) for pending QUIC connections with one linear `foreach` that builds the exact same ordered pending object list. No transport state, caps or callback names changed.
- Add native worker regression with two active QUIC peers, error-removal of one, and verify surviving peer remains polled under a sparse key. Exact-head QA and independent QUIC (PHP 8.4–8.6) **pending**.
- Production classes/files remain 448. Existing B07-S2 default Psalm `TaintedSSRF` remains **red** and is separately tracked; this batch deliberately does not add a suppression or otherwise change outbound security policy.

### B09 — 2026-11-21

- Reused the same lazily created `StreamWriter::writeWarning(...)` closure through a local static variable in `StreamWriter::write`. This avoids allocating a new callback closure for each buffered/immediate `fwrite` attempt; still installs and restores the exact warning handler **per write** in `finally`.
- No change to warnings/notices, short writes, or write-buffer/finalize semantics. Existing disconnected peer, outer handler, wrong-resource and underlying Connection tests retained. New repeated `php://temp` writes with explicit lengths and caller notice restoration guard.
- No class/file removal; `StreamWriter` remains a published patch symbol. No measured per-request throughput gain asserted; source evidence only. Exact-head QA pending; PHPForge's unsuppressed SSRF finding is tracked separately and never suppressed.

### B08 exact-head verification — 2026-11-21

- On exact B08 SHA `a641b0bbfaf597fd1451647a99fdafc6f2561f44`: all seven non-Security required workflows green; inside Security & Standards run `44929656757`, native QUIC 8.4/8.5/8.6, six PHPForge QA lanes, three benchmarks, clean install and service prep all passed. The three PHPForge *analysis* lanes failed on the exact same existing unsuppressed `TaintedSSRF` flow `PsrMessageBridge::outboundRequest → Http2OutboundClient → Dialer::open`, with no newly reported B08 issue. This is **not** an all-green source SHA.
- B08's sparse-key regression passed and production source files remain 448. B09 is a separate code/QA batch. Keep Psalm's default security analysis active; do not merge/tag/release until SSRF is correctly resolved.

### B10 — 2026-11-21

- `LifecycleEmitter` initializes event-failure counters via a direct ordered `foreach`, eliminating `array_map`, `array_fill_keys` and their short-lived arrays. A focused test verifies enum-declaration order, zero values, and listener failure isolation.
- Ownership/compatibility disposition: `AdaptivePollStrategy` has one production caller and direct regression tests; the class is autoloadable and existed in 3.0.1, so co-locating it requires an intentional major-version API migration. `Http1PolicyAttempt` carries mutable state shared by callbacks within one retry/redirect attempt; a global or caller-owned reuse risks reentrancy. `QuicWorkerResourceUsage` is opt-in diagnostics, not normal hot-path work. `ConnectionCallbackDispatcher` preserves exception/cleanup ordering in several call sites; `ContentLengthParser` enforces shared request/response decimal-overflow semantics; `WorkerStopState` coordinates loop ownership in both HTTP and stream workers.
- None of the reviewed helper deletions is independently justified for patch-release compatibility. Source classes/files still number **448**, equal to released 3.0.1. Removing a file merely for an artificial quota is not an optimization. Retain these candidates for the next deliberately breaking release, with measured warm/cold autoload and actual consumer impact.
- Exact-head CI pending; PHPForge's default unsuppressed `TaintedSSRF` blocker is an independent open finding, not fixed or suppressed in B10.

### B11-C1 — 2026-11-21

- Previous `Connection::invoke()` delegated to `ConnectionCallbackDispatcher::invoke()` and allocated a new `fn() => $this->abort()` callback. The connection now calls a non-null user callback directly and catches `Throwable`, attempting abort and preserving the original exception even if cleanup fails. This removes a forwarding call and a Closure allocation without changing the published signature.
- Retain `ConnectionCallbackDispatcher` class and its independent close-callback `dispatch()` path for release compatibility; no unsupported file deletion. Add regression for null callback, exact throwable precedence, and terminal connection state.
- No measured RPM or memory gain asserted; exact-head CI pending. The independently tracked PHPForge default `TaintedSSRF` blocker remains unsuppressed.

### B11-C1-R — 2026-11-21 (Source Audit disposition repair)

- B11-C1 exact-head `90f96c7e1ff9738161aba7b113428efd952a9b9f` Source Audit run `44930750765` failed specifically in the strict 2.1.2-to-3.0 `c4-migration-behavior-audit.php` gate: `Changed public implementations are missing dispositions: Infocyph\\Runwire\\Supervisor\\Internal\\LifecycleEmitter`. Its source changed in B10, and the migration ledger was not synchronized.
- Added exactly one entry for `LifecycleEmitter` to `qa/release-baseline/c4-migration-behavior-ledger.json`, referring to the newly added `tests/SupervisorLifecycleTest.php` regression. The entry records enum-order zero counter initialization and isolation after a throwing listener; `requiresReleaseSignoff` remains true, and `behavioralCompatibilityCertified` remains false.
- No guard script, QA thresholds, Psalm overrides, ignore paths, or production logic changed in this correction. Exact-head Source Audit and all CI must pass before marking green; separately unresolved `TaintedSSRF` remains a blocking security issue.

### B11-C1-R2 — 2026-11-21 (complexity rule correction)

- B11-C1-R SHA `9345ac57cb045718d149ef3c2c72e3ef6413595d` passed strict Source Audit after a new test-backed migration entry. PHP 8.6 PHPStan revealed a separate **new** class complexity failure at `Connection.php:30`: cognitive complexity **84**, required maximum **80**.
- Restore the exact `Connection::invoke()` source from the earlier verified commit `2aa3e13acdf1208dd9738f98eca907d18d96ea71`, returning exception cleanup ownership to `ConnectionCallbackDispatcher`. The B11-C1 negative-path regression stays as coverage. No rule suppression, threshold increase, or new abstraction to game complexity.
- This means the proposed per-invocation abort-closure elimination was **reverted** and is no longer counted as a delivered performance improvement. B08/B09/B10 remain. Exact-head QA pending; default PHPForge `TaintedSSRF` is a separate unresolved security gate.

### B11-M — 2026-11-21 (measurement artifacts and release-gate truth)

- Downloaded the actual `runwire-release-matched-performance` ZIP artifact ID `14716889254` from completed Release Certification run `44927412407`. **This is historical B07-S1 commit `5d3a2cabc0ce1dde3509e051a75a23dd15b639d7`**, not B08–B11. All frozen-source certification jobs for that historical SHA passed, including 30-minute soak and PHPForge release guard.
- **Published 3.0 baseline `054737bc6a8d4025a8d05fabb8ad390f66ee47ae` vs B07-S1**, matched PHP 8.6 plaintext HTTP/1, five paired 180-second trials, concurrency 16: median successful RPS **13,995.129 → 13,817.012** (RPM **839,707.74 → 829,020.72**), a **-1.273%** throughput delta. Median p99 **2.00 → 2.03 ms** (+1.5%); peak RSS **134,070,272 → 133,513,216 bytes** (-0.415%). All responses correct, benchmark passed its 5% regression budget; **no throughput improvement demonstrated**.
- **Pre-optimization structural baseline `f1c99ba83140bf9829406c600296ebe1a78be491` vs B07-S1**: both have **448** production PHP files. Cold CLI bootstrap loaded/cached **0 → 0** Runwire files; lifecycle **43 → 43** loaded and cached files, OPcache script bytes **419,016 → 417,408** (-1,608); coroutine **62 → 62** files, OPcache script bytes **722,440 → 720,648** (-1,792). Small byte reductions do not show fewer file loads or improved sustained throughput.
- New B08–B11 code must be separately measured on one final SHA after quality/security gates; don't reuse the older run's results. B11-C1 callback inlining was **rejected/reverted** when PHPStan class cognitive complexity rose to 84 (maximum 80); B11-C1-R2 `f3eacd05fdd49f9f80709cc50bc2b1e5d81d02f1` restored the original handler and retained a negative-path regression. Do not count that attempted optimization as a success.
- **Unresolved blocking issue:** PHPForge default unsuppressed `TaintedSSRF` from PSR request URI into the outbound dialer (PHP 8.4–8.6); no `psalm.xml` root override, no replacement, no blanket suppression. The true authorization boundary and secure sanitizer modeling need a separate reviewed source change. Final performance certification, full green CI, release readiness, merging and tagging remain **not complete**.

### B12-S1 — 2026-11-22 — Unsuppressed Psalm SSRF root-cause authorization

- Reviewed exact prior source SHA `0584e26e22b8ebac0591a262564c1eb660f20fd7` and Security & Standards run `44932035142`: **seven other required workflow families green, six PHPForge QA lanes green, native QUIC 8.4–8.6 green, PHPStan and Composer security audit green**. All three PHPForge analysis lanes failed on the **same single unsuppressed Psalm `TaintedSSRF`**: `RequestInterface::getUri() → PsrMessageBridge → ClientRequest::$uri → Http2OutboundClient → HappyEyeballsDialer → Dialer::open`.
- Root cause: PSR metadata interop previously copied an attacker-supplied URI directly into an outbound request without a caller authorization boundary. This is not fixed by syntax validation or TLS, and simply suppressing Psalm would hide an exploitable trust path.
- Tighten the PSR interop contract by adding trailing optional `approvedUri` to `PsrMessageBridge::outboundRequest`. **Fail closed** if no configured approval is provided or if the PSR URI differs in any byte (including credentials, scheme, port, path or query). Construct `ClientRequest` using only the explicitly approved value, never the untrusted PSR URI. Existing header/body bounds, cancellation and protocol selection stay unchanged. No raw socket can be opened during adaptation.
- This is an intentional **security-driven behavior tightening** for existing calls without an approved URI. Direct caller-owned `ClientRequest` and low-level local/private `Dialer` paths remain unchanged. For actual DNS-connected dialing, continue to supply `DialOptions::addressPolicy`, which verifies resolved candidates before opening sockets and kernel peers before TLS/handoff; an exact URL check alone does not prevent DNS rebinding. Document the trust contract and add exact-match, missing-approval, private-IP, authority userinfo, port, path and query rejection tests.
- No root `psalm.xml`, taint suppressions, escape annotations, guard relaxations, new production classes or additional network cost. **Implementation complete, security closure pending** exact-head PHPForge default Psalm and PHPStan on PHP 8.4–8.6, security scanner, all QA and native/hosted/source audit checks. If any analysis still reports a path, correct the code rather than lowering severity. Source and loaded PHP class count remains 448.

### B12-S1-D — 2026-11-22 (strict PHP example syntax repair)

- B12-S1 exact-head `14dbad1eb72c9fbaff64d3755363a189c948f78a` had Sphinx documentation workflow failure `44976917447`. The `Validate PHP examples and documentation source references` step reported `Unexpected fully qualified name "\\Runwire"` in newly added `docs/outbound-handbook-3.0.rst:221`. The `use` statement mistakenly contained doubled namespace separators.
- Fix the executable PHP import to single namespace separators, and align the adjacent inline class reference with existing RST conventions. Do not disable the PHP parser or modify Sphinx warnings-as-errors. This commit changes only docs and the tracking plan.
- The source-level SSRF authorization change is B12-S1; exact-head default PHPForge Psalm and PHPStan across PHP 8.4–8.6, six quality suites, native QUIC, hosted and Source Audit must pass before the security item is closed. No production file removals; classmap stays 448.
