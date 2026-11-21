# Runwire — PHPForge 13-section engineering and structural optimization plan

> Scope: Runwire production PHP plus configuration, CI, and tests. Baseline `f1c99ba83140bf9829406c600296ebe1a78be491` (the CI-green head of PR #16). Read-only source sweep informed this plan; it does **not** certify that every function meets every PHPForge rule.
>
> Principles: `infocyph/PHPForge/resources/engineering-principles.md` at `c1f68be02b22a4d3377b7be91f563b12e7f68955`. Source of authority: rule IDs, not intuition.
>
> Execution: **one batch = one commit**; update this file's bottom tracker in the **same** commit as the batch changes. Do not modify main/master, auto-merge, release, or tag. This branch starts from PR #16's exact verified head, and the optimization PR should be stacked on that branch until PR #16 is integrated.

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
- [x] Create a separate optimization branch without modifying PR #16's release-ready head.
- Gate: plan file exists in the branch and can be updated along with later batches.

### B01 — Security boundary and PHP 8.6 capability truth [queued, P0]

- [ ] Correct stale *verified vs pending* PHP 8.6 host/portable statements to match exact GitHub CI evidence, preserving optional extension limitations.
- [ ] Document the dialer taint-analysis exception's explicit trust model, narrow scope, independent approval/review requirement and removal trigger without weakening any detector.
- [ ] Add or verify focused SSRF regression coverage for candidate and kernel-reported peer, without changing public low-level TCP dialing semantics.
- [ ] Record all affected docs/tests and quality-gate results.
- Gate: docs/Sphinx, source audit, negative network/SSRF tests, complete required CI on this head.

### B02 — PSR-15 per-request work [queued, P1]

- [ ] Inventory adapter callers and terminal behavior, including empty, one and 32 middleware.
- [ ] Evaluate immutable handler-chain construction **once per adapter** rather than rebuilding up to 32 layers on each request. Preserve middleware order, short-circuit, exception and per-request attributes.
- [ ] Add multi-request, reentrant and exception/short-circuit tests before considering reduction.
- [ ] Measure repeated handling at identical configuration, cold setup, memory/allocation and representative successful RPM; report observations, not assumed gains.
- Gate: focused PSR tests + full analysis/QA and parity; no native-reactor synchronous middleware.

### B03 — HTTP/1 hot connection setup and call hops [queued, P1]

- [ ] Verify per-connection construction cost, usage and lifetime of RequestHeadValidator, ChunkSizeDecoder, Http1Syntax and WebSocketUpgradeOwner.
- [ ] Inline the three trivial WebSocketUpgradeOwner retain* pass-through methods where semantics truly unchanged.
- [ ] Only consider lazy handoff or shared immutable stateless parser helpers if callback/lifetime ordering remains identical and measurement justifies it.
- [ ] Keep Http1Input ByteQueue ownership, protocol parser limits and exact error statuses.
- Gate: HTTP/1 malformed/slowloris/chunks, H1→WebSocket handoff, streaming/backpressure tests and RPM checks.

### B04 — HTTP/3 duplicate validation and preflight [queued, P1]

- [ ] Inspect Http3OutboundClient and sibling callers for duplicate original-origin parse, temporary Http3ClientWireCodec allocation and QPACK encoder state preflight.
- [ ] Optimize only after ensuring per-sibling origin and h3 ALPN checks still run, and error ordering/atomicity is preserved.
- [ ] Keep independent QUIC request, GOAWAY, TLS peer, QPACK blocked/unblocked and cancellation invariants.
- Gate: independent HTTP/3 QUIC PHP 8.4/8.5/8.6 tests, multiplex/cancellation, security and throughput.

### B05 — Small internal type and call-chain audit [queued, P2]

- [ ] Map real use count and reason-to-change for Http3ClientGoawayState, small HTTP/3 helpers, CallbackDispatcher, PreparedCommand, HostDriverFactory, RuntimeSelection, one-off internal state holders.
- [ ] Remove only verified redundant wrappers, private delegation, or trivial classes when no independent policy, state, public API or lifecycle exists.
- [ ] Preserve BoundServer/BoundStreamServer/BoundDatagramServer type constraints unless proof permits a narrower replacement.
- [ ] Report exact before/after file/class/interface count and actually loaded files; do not create an artificial target.
- Gate: public-api manifest, classmap, cross-protocol suites and negative cases.

### B06 — Memory, observability and ownership pass [queued, P2]

- [ ] Profile QPACK result/state allocation and avoid unnecessary copies without erasing protocol state.
- [ ] Review metrics, idle callback/trace paths, DNS/cache refresh and pool lifetimes for repeated work, stale generations and leaked resources.
- [ ] Keep useful immutable value objects and security/lifecycle constraints even if that leaves file count unchanged.
- Gate: bounded-memory/soak/cancellation/fork and relevant cross-protocol tests.

### B07 — Final measurement, tracker closure, and release handoff [queued, P0 gate]

- [ ] Recount production files/interfaces/enums, relevant call hops and autoloaded scripts; summarize actual changes.
- [ ] Compare baseline vs exact SHA at production-equivalent concurrency with repeatable successful RPM, p95/p99, memory and error budgets.
- [ ] Confirm PHP 8.4–8.6, optional extensions, docs, public API, packaging, security and final-source SHA; report any unsupported combination precisely.
- [ ] Separate completed code work from release certification, main-branch merge or tagging; **never automatically merge**.
- Gate: all required CI green on final exact SHA; blockers explicit.

## Rolling tracker — append a dated entry *below* after every batch

| Batch | Status | Commit SHA | Evidence | Residual / next |
| --- | --- | --- | --- | --- |
| B00 | Complete | Plan-creation commit | 13-section source inventory and scoped tasks recorded | B01 |
| B01 | Queued | — | — | docs and SSRF review |
| B02 | Queued | — | — | PSR chain |
| B03 | Queued | — | — | HTTP/1 |
| B04 | Queued | — | — | HTTP/3 |
| B05 | Queued | — | — | type budget |
| B06 | Queued | — | — | memory/observability |
| B07 | Queued | — | — | measured final gate |

### B00 — 2026-11-21

- Source snapshot: 448 production PHP files and 338 test PHP files from PR #16 head `f1c99ba`; PHPForge principle reference `c1f68be`.
- Conclusive hot-path finding: `PsrMiddlewareAdapter::handle()` constructs a bounded chain repeatedly (up to 32 middleware per request).
- Structural review candidates require caller-graph proof; no production class has been approved for deletion merely by size.
- Verification status: historical original head passed required CI; this plan-only commit has not independently established fresh CI. No performance improvement has been measured.
