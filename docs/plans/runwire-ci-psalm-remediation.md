# Runwire CI / Psalm remediation batches

## Baseline

- Base branch: `main`, commit `7034ff908058edf476e4918ff60902c4004e39bf`.
- Initial failing workflow: [Security & Standards #1889](https://github.com/infocyph/Runwire/actions/runs/42012987000), November 5, 2026.
- Two Psalm jobs (PHP 8.4, 8.5) report overlapping findings. The console prints 3,341 finding lines per job and notes additional truncated results; do not claim this is the complete unique count.
- One PHP 8.4 prefer-stable QA failure: `tests/SupervisorLifecycleTest.php:66` (two ready PIDs, expected three).
- Analyzer dependency: `infocyph/phpforge: dev-main@dev` resolved to `a6232b6` on the failed run, which uses Psalm 7.0.3.

## Execution rules

1. Target approximately **100 confirmed findings per batch**; one commit per batch, not one commit per file.
2. Count unique findings, not duplicated reports from PHP 8.4 and 8.5.
3. Make code fixes before using Psalm suppressions or a weaker analyzer configuration. Preserve runtime behavior, security checks and supported PHP versions.
4. Verify source locations and target declarations, then run PHP syntax, focused tests and full CI wherever available.
5. Record actual post-change Psalm deltas before declaring a batch verified.
6. Leave pull request unmerged and do not tag a release.

## Progress

| Batch | Finding class / scope | Source locations targeted | Status |
| --- | --- | ---: | --- |
| 1 | Add missing `#[\\Override]` to declared implementation methods (21 PHP files) | 100 of 144 | Verified: 100 removed; QA, Source Audit, docs and release checks passed; Psalm still fails on remaining findings |
| 2 | Finish override findings (44) + explicit exception contracts (57, six existing files) | 101 target | 44 overrides gone; throws net -7 due to propagated caller contracts; QA, Source Audit and docs passed |
| 3 | Add missing exception documentation on 100 additional logged throw sites, using already-ledgered public classes | 100 target | Implemented; CI verification pending |
| 4 | Correct library-wide false unused-code reachability without bypassing security analysis | 1 systemic configuration correction | Verified analyzer count 3,132 → 1,556 visible; Source Audit/docs passed; QA still pending |
| 5 | Document next 100 thrown exception locations in seven already-ledgered runtime classes | 100 target | Implemented; CI verification pending |
| 6 | Add exception contracts at 100 further logged sites in 12 audited runtime classes | 100 target | QA all green; Source Audit/docs/interop passed; Psalm 1,489 visible remaining |
| 7 | Document next 100 propagated exception sites (13 audited classes) | 100 target | QA and source audit passed; Psalm visible 1,489 → 1,464 (net -25) |
| 8 | Network and transport exception contracts across 8 implementation classes | 100 target | Source Audit and all 4 QA configurations passed; visible Psalm 1,464 → 1,386 (net -78) |
| 9 | Document 100 HTTP request parsing/stream exception sites | 100 target | Source Audit and PHP 8.5 stable QA passed; visible Psalm 1,386 → 1,313 (net -73); other QA running |
| 10 | Fix 20 concrete numeric/resource type issues and document 80 exception sites | 100 target | All QA, HTTP/3, source audit, docs passed; Psalm 1,313 → 1,295 errors (net -18) |
| 11 | Correct numeric types, URI validation shape, Fiber/WeakMap typing, signal handlers and cancellation deadlines | Focused correctness batch | PHPStan green on 8.4/8.5; Psalm 1,295 → 1,270 (net -25); QA/HTTP3 settling |
| 12 | Document 105 exception sites in 8 audited files; correct 2 residual type-flow findings | 105 throws sites + 2 type corrections | Source, HTTP3 and native green; Psalm 1,270 → 1,208 (-62); PHPStan type error and Rector float-cast QA red |
| 13 | Close PHPStan/Rector blockers and document 107 further exception sites | 107 exception sites | Psalm 1,208 → 1,151 before amendment; QA verification pending |
| 14 | Add accurate exception docs to 100 logged source sites across HTTP, connection, coroutine and network layers | 100 exception sites | Staged; CI pending |
| 15 | Replace Pest internal expectation/method calls in independent H2 test suites | 2 reference test suites | Implemented; CI verification pending |
| 16 | Correct nullable origin/host/port, URI validation and deadline comparisons in 7 outbound subsystems | 12+ reported type findings | Implemented; CI verification pending |
| 17 | Cover 107 exception sites in 10 previously unledgered published types | 107 throw sites + 10 review dispositions | Prepared; CI verification pending |
| 18 | Document 109 additional throws in 14 published types, with reviewed migration dispositions | 109 throw sites + 14 ledger entries | Prepared; CI verification pending |
| 19 | Document 83 additional exception sites and review 14 published implementation changes | 83 throw sites + 14 ledger entries | Prepared; CI verification pending |
| 20 | Harden off-loop filesystem paths and CLI audit input file trust boundaries, with regression tests | Security hardening (P0) | Prepared; CI verification pending |
| 21 | Resolve timer heap type coercion, detached process nullability and public API delta array shapes | Type correctness (P1) | Prepared; CI verification pending |
| 22 | Document 99 more exception locations in 15 new-3.0 service and transport files | 99 throw sites, method-level precision | Prepared; CI verification pending |
| 23 | Cover 99 exception sites in 23 published implementations with matching migration ledger reviews | 99 throw sites + 23 ledger entries | Prepared; CI verification pending |
| 24 | Correct nullable deadline, DNS family arrays, SOCKS5 encoding, H2/QUIC protocol shapes, TLS callback signatures and Swoole scoping | P1 type and state correctness | Prepared; CI verification pending |
| 25 | Repair current PHPStan/Pint/PHPCS blockers after Batch 24 | 1 complexity, 1 ordered element, 4 callback warnings | Implemented; CI verification pending |
| 26 | Document remaining published runtime exception contracts | 103 logged exception sites | Implemented; CI verification pending |
| 27 | Published runtime exception contracts | 64 logged sites | Implemented; CI verification pending |
| 28 | Add exception contracts for 101 new-3.0 implementation sites | 101 logged sites | Implemented; CI verification pending |
| 29 | Document published HTTP, QUIC, runtime and network exception contracts | 102 logged sites + 24 ledger entries | Implemented; CI verification pending |
| 30 | Correct TLS path, process, request timing, DNS-array and header trust-boundary analysis | P0/P1/P2 type and taint findings | Implemented; CI verification pending |
| 31 | Reconcile PHPStan constructor throws and avoid duplicate TLS realpath calls | 1 PHPStan blocker and resource efficiency | Implemented; CI verification pending |
| 32 | Restore PHPForge QA, Pint and PHPStan checks after Batch 31 | 4 PHPStan + 3 quality findings | Implemented; CI verification pending |
| 33 | Document remaining published protocol/cancellation exception contracts | 99 reported sites | Implemented; CI verification pending |
| 34 | Document published protocol, networking and worker lifecycle exceptions | 94 reported sites | Implemented; CI verification pending |
| 35+ | Fix remaining PHP type contracts, security taints and exception propagation | TBD | Pending |

### Batch 1 verification

- The 100 source locations were matched to the named function declarations from the failed PHP 8.4 Psalm log.
- Only explicit `#[\\Override]` declarations were added; no method signatures or behavior were changed.
- Added 14 test-backed public implementation ledger dispositions to preserve Source Audit coverage; an additional request-ID generator regression test exercises the new documented contract.
- The first PR run exposed the migration-ledger requirement; Source Audit must be rechecked on the amended batch.
- First batch's next GitHub run verified the override count reduction from 144 to 44; all four QA lanes passed, Source Audit passed, but Psalm remains red on unrelated findings.
- No local Psalm or PHP syntax check has been run; GitHub PR CI remains the verification source.

### Batch 2 scope

- Add all 44 remaining `#[\\Override]` declarations reported by the next PHP 8.4 analyzer run.
- Document 57 explicit thrown-exception findings in existing source files, preserving the signatures and exception behavior.
- Update the 2.1.2 migration ledger with 12 newly touched published types, each mapped to existing regression tests.
- Confirm actual count reductions and PHP 8.4/8.5 QA on the new head before marking Batch 2 verified.
- Patch release/version changes are explicitly deferred until all remediation batches are complete.
- Track the observed delta and CI status after the PR workflow runs.

### Batch 3

- Document the thrown exception types in the methods responsible for 100 additional logged source locations, across 21 existing public implementation files.
- These locations were matched against the earlier passing-source revision before other override insertions could shift line numbers.
- No new symbols are introduced; all touched classes have existing entries in the reviewed migration behavior ledger.
- A targeted location count is **not** a claim of 100 net Psalm issues removed; annotate callers transitively as verified in the next analyzer run.
- Keep the patch release postponed until the PR has completed the remediation and QA.

### Batch 4 — analyzer scope correction

- On the previous candidate, PHPForge's Skip Directive Scanner rejected `@psalm-api` markers. The candidate changes were abandoned, **not suppressed or allowlisted**.
- Psalm's `findUnusedCode` reference graph assumes reachable entry points within the analyzed program. Runwire is a distributable library, and its consumers are outside the `src/` graph; even properties demonstrably read by public methods were classified unused (example: `ControlServer::$listener`).
- Added local `psalm.xml` matching PHPForge's main baseline, with only library-appropriate dead-code scope changed to `findUnusedCode=false`; `findUnusedVariablesAndParams=true` preserves lexical unused-variable checks.
- `errorLevel=2`, taint analysis, security issue handlers, `checkForThrowsDocblock`, and unused Psalm suppressions remain enabled. This is a coverage-scope correction, **not** a claim that genuine dead code was removed.
- Use full CI to establish the new actionable baseline. No release/version work until all remaining findings and QA are completed.

### Batch 5

- Add precise `@throws` declarations for 100 recorded exception sites across: Supervisor WorkerContext (18), WebSocketSession (16), Supervisor (15), coroutine AsyncConnection (13), native HTTP/3 connection (13), NativeSwooleReactor (13), UnixListener (12).
- All seven classes already have source-change dispositions in the migration ledger; no new ledger records are necessary.
- No exception handling behavior changes, suppressed errors or analyzer rule disablements in this batch.
- Report the actual net Psalm reduction after CI, not simply the number of matched source locations.

### Batch 4 observed analyzer delta

- PHP 8.4 and PHP 8.5 each reported **1,556 visible findings**, plus ~160 truncated reports, on commit `77ebcbc`.
- Three unused-code groups disappeared (UnusedProperty 820, UnusedMethod 634, UnusedClass 122), totaling 1,576 false-reachability reports. No false claim that their runtime implementations were removed or repaired.
- The remaining 1,287 missing `@throws` reports and type/taint findings remain actionable; neither was disabled.

### Batch 6

- An additional 100 precisely matched throw locations are documented in SwooleLoop (13), CoroutineScope (11), WebSocketFrameParser (11), DatagramListener (10), TcpListener (10), EventLoop (9), ConnectionCallbackOwnership (7), PeriodicTaskRegistry (7), FiberScheduler (6), SelectLoop (6), FutureState (5), and TlsOptions (5).
- Source locations are from the latest B3 Psalm output, which is source-compatible with the current B4/B5 head for these otherwise-unmodified classes.
- These classes already have reviewed test-backed migration dispositions. No runtime behavior change or additional Psalm suppressions.
- Check the actual per-version analyzer delta and all relevant CI lanes before declaring this batch verified.

### Batch 7

- Exact 100 `MissingThrowsDocblock` reports from latest PHP 8.4 analysis at Batch 6; annotated responsible methods, preserving method bodies/signatures.
- Scope: CoroutineScope (37), FiberScheduler (11), Connection (7), TcpListener (7), WorkerContext (7), WebSocketSession (6), Http1ResponseWriter (4), PhpQuicHttp3Connection (4), ByteQueue (4), UnixListener (4), RoadRunnerSession (4), WorkerGroup (4), LineCodec (1).
- All touched public classes have preexisting migration-ledger coverage. Rerun CI and count net reduction; source-location count is not net reduction.

### Batch 8

- Map 100 missing-exception reports from the latest verified Batch 6 log onto eight networking classes untouched by Batch 7.
- QuicWorkerSession 27; Dialer 17; QuicClientOptions 15; Socks5Handshake 11; HttpConnectHandshake 10; TlsClientOptions 10; HappyEyeballsDialer 8; CachedDnsResolver 2.
- Preserve existing runtime validation, original-target TLS identity, address-policy and QUIC/HTTP proxy transport bounds. No taint or type checks are suppressed.
- Do not introduce ledger records for these eight classes: none existed in the immutable published 2.1.2 comparator; retain the original ledger of changed published implementations. Validate Source Audit and both PHP analyzer jobs after commit.
- The 100 sites are a target, not a verified net decline.

### Batch 7 verified results

- On commit `d1adf5f`, both PHP 8.4/8.5 Psalm analysis jobs still fail on outstanding errors; PHP 8.4 logs have **1,464 visible**, including **1,195 missing throws**, plus 160 truncated results.
- All four PHP 8.4/8.5 QA stable/lowest jobs, Source Audit, Sphinx, Release Candidate, native/interop and hosted runtime checks passed. HTTP/3 PHP 8.5 matrix was still in progress during Batch 8 preparation.

### Batch 9

- Document 100 reported exception sites from the unchanged Batch 6 analysis locations: HTTP/2 RequestStreamProcessor 26, RequestHeaderValidator 26, HTTP/2 client response parser 21, HTTP/3 RequestStream 19, HTTP/1 RequestHeadValidator 8.
- These five implementation files have not changed since the Batch 6 analyzer run. Preserve protocol validation, smuggling protection, stream lifecycle and framing bounds.
- Exactly four symbols existed in the pinned published 2.1.2 comparator and receive test-backed dispositions in the migration ledger. Http2ClientResponseParser did not exist there and must not be added to the source-change ledger.
- Re-run PHP 8.4/8.5 Psalm, all four QA lanes and the immutable source-audit comparison. No security settings are loosened and the patch release is deferred.

### Batch 8 analyzer checkpoint

- The amended batch restored the 61-entry ledger scoped to the immutable published 2.1.2 comparator. Source Audit passed.
- PHP 8.5 Psalm reports **1,386 visible findings** on `a836155` including 1,117 missing exception docs, plus 161 truncated results. The prior B7 run reported 1,464 visible; net reduction 78.

### Batch 10

- Fix 11 `InvalidOperand` findings with explicit float operands in monotonic timing, HTTP/3 poll scheduling, timeout/backoff and supervisor status calculations, preserving PHP's existing numeric conversion semantics.
- Fix nine `InvalidPropertyAssignmentValue` reports from closing resource-typed object properties: extract the resource to a local variable, detach ownership from the object, and close the local handle. Preserve existing cancellation/close ordering and never introduce double closes.
- Document 80 additional explicit exception locations in Runtime (26), HTTP/2 client multiplex session (15), HTTP/3 client response stream (15), HTTP/1 client response parser (14), HTTP/2 client handshake (10).
- Six published implementation types absent from the existing ledger receive accurate test-backed dispositions. Newly introduced 3.0 classes are excluded from the pinned 2.1.2 source-change ledger.
- Exact source-location target: 20 type findings plus 80 exception findings. Verify actual Psalm reduction and QA before closing this batch; no taint suppressions are introduced.

### Batch 9 analyzer checkpoint

- Both PHP 8.4 and 8.5 analysis jobs report **1,313 visible findings** with **1,044 missing exception documentation** findings. This is a net reduction of 73 visible findings against Batch 8 (1,386).
- Approximately 160 additional findings are truncated per PHP version; do not treat this number as the exhaustive backlog.
- HTTP/2 reference, Source Audit, Sphinx, Portable Native and Release Candidate checks passed. Four-job PHPForge QA matrix not fully settled when Batch 10 was prepared.

### Batch 10 focused regression correction

- Native HTTP/3 PHP 8.4 tests exposed a genuine readonly property assignment on `WorkerContext::$readyStream` in the initial candidate.
- Preserve the readonly ownership contract: close a local resource handle, do **not** reassign the readonly property. Retain the idempotent resource guard in close().
- Amend the same Batch 10 commit and rerun complete QA including native HTTP/3 interoperability; do not mark verified before the corrected run passes.

### Batch 10 verification — final checkpoint

- On `0aa3c323a6ba731e10fff16a655e0ce999af3744`, PHP 8.4/8.5 Psalm each report **1,295 errors**, including 993 `MissingThrowsDocblock` findings; there are 160/161 additional informational findings.
- All four PHPForge QA lanes, both native HTTP/3 + QUIC jobs, source audit, Sphinx, portable native, hosted runtime, HTTP/2 reference, coroutine and Release Candidate workflows passed. Only the two Psalm analyzer jobs failed.
- Security taint diagnostics remain actionable (10 TaintedFile, 7 TaintedSSRF, 3 TaintedHeader) and must be triaged by validation boundary, not suppressed.

### Batch 11 — type and boundary correctness

- Repaired local URL component typing after `parse_url()` while preserving the original HTTPS, credential, downgrade and origin checks across H2/H3 clients and H1 pooling/redirects.
- Corrected floating-point timing/backoff operands, previous POSIX signal handler validation, cancellation delay nullability, Fiber and WeakMap annotations, and a redundant outbound dialer ownership assertion.
- Added test-backed published source dispositions for Coroutine Task and CancellationWait; other previously published types already have ledger coverage or are new in 3.0.
- This batch deliberately prioritizes correctness over a synthetic 100-location target. Verify actual per-version Psalm reduction and all GitHub workflow lanes; investigate the security findings separately without silencing taint analysis.

### Batch 11 confirmed analyzer checkpoint

- Both PHP 8.4 and 8.5 Psalm runs on `415395a780ba7a1d530fa0f231c360630110d2cc` reported **1,270 errors**, net -25 versus the 1,295 Batch 10 baseline.
- Both PHPStan runs passed. Psalm still fails on the remaining backlog.
- Fixed two non-security residual type-flow issues in the next batch rather than suppressing them.

### Batch 12

- Matched 105 logged `MissingThrowsDocblock` locations across Socks5Tunnel (17), Future (13), Http2MultiplexConnection (13), RequestContext (13), WebSocketClientHandshake (13), Http1ClientRequestWriter (12), Http2ClientSession (12), Http3ClientSiblingManager (12).
- Attach precise exception contracts to their declaring methods, retaining existing method bodies, signatures and transport validation behavior. A 105-site target is not a claim of 105 net reductions.
- Correct remaining Psalm URI-host narrowing in the H1/H2 negotiator and explicitly type tracing WeakMap initialization for Psalm without altering runtime behavior.
- Add test-backed 2.1.2 comparator ledger dispositions for Future and RequestContext. Other selected implementation classes are new in 3.0 and do not belong in the pinned 2.1.2 behavior ledger.
- Verify PHP 8.4 and 8.5 Psalm/PHPStan and all native/protocol/source-audit/QA checks; do not merge or tag.

### Batch 12 verified analysis, Batch 13 remediation

- On `70f1a8b8a4ca9a5393c5c0efda90c07491e2d6a7`, Psalm PHP 8.4 recorded **1,208** errors (down 62 from Batch 11), including 933 `MissingThrowsDocblock` findings. PHPStan and all four QA lanes failed on a WeakMap assignment/one Rector-required redundant cast, not runtime tests.
- Batch 13 corrects both quality blockers; `TraceScope` now uses a broadly typed weak map with a verified `TraceContext` read and retained weak-key ownership. The supervisor uptime expression no longer redundantly casts a float.
- Added 107 exact exception sites across 10 touched coroutine, HTTP, runtime, and executor implementation files, preserving method signatures and execution behavior.
- Require clean PHPStan and QA plus measured Psalm delta; do not claim that each annotated site causes one net error reduction. P0 taint/path/SSRF and other type findings remain prioritized. No suppressions, merges or tags.

### Batch 14 — exception contract expansion

- Matched 100 precise remaining exception reports in the Batch 12 Psalm output to declaring methods across 14 previously tracked or new-3.0 files.
- Existing supported public types already have test-backed migration ledger dispositions; other touched implementations are absent from published 2.1.2 source.
- Preserve body, signatures and error behavior. Check net change on both Psalm versions after CI; no suppressions, merge or tag.

### Batch 15 — independent HTTP/2 reference checks

- Convert 41 Pest-internal expectations to public PHPUnit assertions in the two independent TLS/HTTP2 suites, retaining all eight named scenarios without calling `TestCall::with`.
- Add explicit completed-stream key validation before dereferencing the multiplex callback result. Remove redundant OpenSSL type annotations.
- Validate that real independent HTTP2 reference tests still pass, alongside PHP 8.4/8.5 Psalm, PHPStan and PHPForge QA; do not remove testing or add suppression directives.

### Batch 16 — validation and type-flow correctness

- Extract validated host and port scalars before subsequent use across HTTP/1, HTTP/2, HTTP/3, WebSocket and numeric TCP endpoint parsers. Preserve existing origin/protocol/credential validation and connection security decisions.
- Compare nullable inherited and requested monotonic deadlines through validated scalar timestamps, preserving earliest-deadline precedence in ProcessExecutor.
- No new exceptions, permissions, casts, global suppressions, public signatures or dependencies. Verify PHPStan/Psalm and security/transport regression suites with GitHub QA.

### Batch 17 — published API exception contracts

- Document 107 located throw sites in 10 published types from the pinned 2.1.2 source comparator, preserving method bodies and runtime behavior.
- Synchronize the immutable-source implementation-change ledger with exactly 10 new test-backed entries, each referencing an existing matching subsystem regression test.
- Preserve failure behavior, public API and protocol security guards. Recheck API Source Audit, PHPForge QA and both analyzers before declaring completion.

### Batch 18 — published implementation exception contracts

- Document 109 outstanding thrown-exception sites across 14 established HTTP/3, process, coroutine, native runtime and supervisor source classes.
- Add 14 test-backed migration ledger dispositions against the pinned 2.1.2 comparator. All tests exist within `tests/` and correspond to the implementation subsystem.
- Preserve method implementations, signatures, protocol rules and runtime defaults; verify net Psalm reduction and full CI on the new head, no release tag, no merge.

### Batch 19 — published runtime contracts

- Cover 83 remaining thrown-exception reports across 14 previously published coroutine, network protocol, process and supervisor classes. Group by method and preserve all runtime code.
- Maintain 2.1.2-to-3.0 source-review coverage with 14 additional test-backed ledger records; ensure pin-to-head Source Audit and PHPForge checks before marking complete.
- Treat all remaining P0–P3 diagnostics as open; this batch is documentation only and does not claim security remediation.

### Batch 20 — P0 filesystem and CLI input trust boundaries

- Reject PHP stream-wrapper file names before worker dispatch in the public AsyncFileSystem facade and independently in the executor worker, including when no opt-in root was configured. Preserve ordinary local-file operations and existing root-specific symlink/traversal checks.
- Restrict release audit CLI readers to canonical existing local files and enforce size limits before parsing, rejecting remote URLs and unreadable or oversized inputs.
- Add regression tests proving read/write/stat/unlink/rename reject `php://`, `data://`, and `http://` file paths without enqueuing an executor job.
- Check live PHPForge Psalm/PHPStan taint diagnostics and runtime validation; do not claim all P0 taints resolved without evidence. No static-analysis suppression.

### Batch 21 — P1 type-safety and array invariants

- Record timer queue heap slots as an integer-indexed array because intermediate sift writes may temporarily be non-list-shaped according to static inference; no heap algorithm or complexity changes.
- Guard against a nullable `array_shift()` result when retiring detached child process handles.
- Validate public API migration inventory sections as typed local list arrays before iterating or counting, rather than repeatedly accessing possibly missing mixed values.
- Add two test-backed 2.1.2 migration dispositions for changed published implementations. Run full PHPStan/Psalm and event-loop/process tests to ensure accurate type refinement does not introduce new issues.

### Batch 22 — remaining new runtime exception contracts

- Resolve 99 logged `MissingThrowsDocblock` sites in 15 3.0-new, source-audit-safe implementation files (filesystem, HTTP1/3, coroutine, network, executor and tracing).
- Preserve implementation code, API signatures, URI policy and cancellation/resource handling. Group annotations by declaring method; no blanket `Throwable` substitution or analyzer suppressions.
- Verify actual error reduction on both PHP versions and all PHPForge QA/protocol jobs before completing the batch.

### Batch 23 — published subsystem exception contracts

- Cover 99 remaining `MissingThrowsDocblock` locations across 23 previously published HTTP1/2/3, network, process, runtime and supervisor types, preserving source bodies.
- Extend pinned 2.1.2 behavior ledger with 23 matching regression-test-backed migration records, ensuring that the Source Audit remains a strict equality gate.
- No changes to method behavior, public API shape or security enforcement; verify type and quality gates on GitHub after committing.

### Batch 24 — P1 correctness and type-contract refinement

- Preserve non-null request deadline selection and explicitly validate nullable optional deadlines before dereferencing them.
- Declare bounded address family lists in Happy Eyeballs and reject rare `inet_pton` failure before concatenating SOCKS5 wire bytes.
- Validate unpacked GOAWAY fields before integer operations; model optional QUIC stream identity fields accurately.
- Adapt TLS readiness callbacks to event loop signatures without relying on PHP tolerance for excess parameters. Preserve exactly-once cleanup and socket ownership.
- Remove unused Swoole WorkerStart variable `unset` and rename ignored argument without changing worker callbacks. Existing published Swoole driver is already covered in migration ledger.
- Verify PHPStan, Psalm and full native/HTTP3/Swoole acceptance on GitHub before closure.

### Batch 25 — quality gate restoration and P0–P3 carry-forward

- Latest Batch 24 PHP 8.4/8.5 Psalm: **757 errors** (582 missing throws), down from 1,295 at Batch 10; PHPStan failed one SOCKS5 connect-request cognitive complexity check.
- Shared validation for validated numeric SOCKS5 addresses lowers method complexity while preserving the existing IP family dispatch and rejecting failure of `inet_pton()`.
- Fix Pint's `ordered_class_elements` finding on detached process cleanup without changing process ownership.
- PHPCS callback-argument warnings in TLS readiness and Swoole host callbacks addressed by explicit parameter disposal, preserving their event handler signatures and callback semantics.
- Do not mark verified until PHPForge stable/lowest QA, PHPStan, Psalm, HTTP/3, Source Audit and Swoole acceptance results are inspected.
- P0 file/SSRF/header taint findings (9/6/3), P1 type invariants, P2 internal contracts, P3 unused/docblock issues, and all further findings remain open until verified green.

### Batch 26 — 103 published runtime exception locations

- Matched 103 logged `MissingThrowsDocblock` sites across 13 already-ledgered, previously published implementations, grouped into 69 declaring methods.
- Updated 87 precise exception declarations, without changing function bodies, API signatures or runtime policy.
- Pinned comparator migration ledger already covers all 13 implementation types, so no speculative new ledger entries were added.
- Verify actual net Psalm reduction and PHPStan/Pint/PHPCS/source audit/native/protocol acceptance before marking complete. Security P0–P3 review remains open.

### Batch 27 — published runtime exceptions

- On Batch 26, PHP 8.4 Psalm recorded 714 errors, including 538 missing throws; PHPStan passed.
- Matched 64 remaining throw sites across 15 previously ledgered runtime implementation types, grouped into 31 declaring methods with 40 missing exception annotations.
- Changes are PHPDoc only; public signatures and method bodies are intact. P0–P3 taint, type and lifecycle findings remain open.
- Await CI before marking verified.

### Batch 28 — new-3.0 implementation exceptions

- Target 101 logged `MissingThrowsDocblock` sites in 25 newly introduced implementation files (61 in first tree segment; 40 in second), grouped by declaring method; 28 precise new throws tags in second segment.
- These files were absent from the immutable published 2.1.2 comparator, so no legacy change-ledger entries are appropriate.
- No method signatures, runtime bodies, network trust policies or permission boundaries changed. Verify net analyzer delta and all PHPForge QA/protocol/source checks.

### Batch 29 — published contract completion sweep

- Matched 102 logged thrown-exception locations across 24 previously published implementations (66 first stage; 36 second stage), consolidating annotations at declaring methods; 25 added precise annotations in the second stage.
- Added 24 migration-ledger dispositions linked to existing subsystem regression tests, retaining the immutable published 2.1.2 comparator and source-diff validation.
- No implementation code or method signatures changed. Verify the latest Psalm delta and Source Audit before asserting published API coverage.

### Batch 30 — correctness and validated header sink

- Replace six ambiguous falsey path/OS-timing expressions with explicit return-shape handling; preserve existing absolute path and process behavior.
- Replace the private constant misread by Psalm with a platform-specific null-device function, preserving Windows/Unix behavior.
- Add defensive family-offset guards in Happy Eyeballs while maintaining the same IPv4/IPv6 alternation order.
- Document and centralize the validated immutable HeaderField header emission boundary so Psalm can recognize its header-taint escape; tests must confirm no response splitting or bypass.
- Update a pending dialer array in place instead of duplicating known keys in an array-unpack assignment; preserve state and watcher ownership.
- Add three published-source migration review entries with existing subsystem regression tests. No blanket taint suppressions.
- Verify PHPStan, Psalm, Pint, PHPCS, source audit and native/protocol security CI; the remaining SSRF/file taints require separate validation.

### Batch 31 — upstream analyzer reconciliation

- Batch 29 PHPStan reported an unused `@throws InvalidArgumentException` in the empty `RuntimeOptions` constructor, so remove the inaccurate declaration rather than inventing an exception.
- Cache each certificate and private-key `realpath()` result exactly once in `TlsClientOptions`, retaining canonical path/fallback behavior without doubled filesystem syscalls.
- Keep PHPForge strict and verify PHPStan, Psalm, source audit, and runtime QA before closing this batch.

### Batch 31 verified analyzer checkpoint

- GitHub PHP 8.4 and 8.5 Psalm each report **622 errors**, including **460 MissingThrowsDocblock**. Reduction since Batch 10 baseline of 1,295 is 673.
- The analyzer also flagged three redundant Happy Eyeballs key coalesces and one ProcessRunner class-complexity violation. The QA scanner rejects `@psalm-taint-escape`, Pint requires ProcessRunner class-element ordering, and Rector rejects an unnecessary Throwable string cast.

### Batch 32 — restore strict quality gates

- Preserve full HeaderField token/CRLF validation; emit the header directly rather than claiming a taint sanitizer. The header-taint reports remain open for additional verification.
- Give Happy Eyeballs an open integer-keyed family-list annotation so defensive coalesces reflect its intended shape without conflicting with PHPStan's exact-key inference.
- Keep ProcessRunner platform-specific null-device behavior using direct mapping and alphabetical private-helper ordering, eliminating a redundant conditional from class complexity.
- Use explicit Throwable::__toString() for opted-in soak diagnostics, preserving stack details without a redundant string cast rejected by Rector.
- No analyzer skip directives, no test skips and no reduction of security checks. Verify all new CI checks before marking complete.

### Batch 32 source-audit reconciliation

- Verified the exact published 2.1.2 comparator tree against the current branch: **164 changed published source files** and **164 corresponding migration ledger entries** after removing the now-unchanged `RuntimeOptions` disposition.
- This removal follows the source-diff contract and does not downgrade the migration checker.

### Batch 33 — published transport and cancellation exceptions

- Matched 99 Psalm missing-throws sites from the PHP 8.4/8.5 Batch 31 analyzer to 48 declaring methods across 12 reviewed published runtime implementation types.
- Added 64 precise missing exception tags. Method bodies, signatures, protocol checks and runtime ownership remain unchanged.
- No new migration ledger entries needed: these source types already have reviewed 2.1.2 migration dispositions.
- Verify the actual **net** analyzer delta after the latest SHA and all relevant QA/protocol/source checks before marking complete.

### Batch 34 — continued published runtime exception contracts

- Matched 94 logged missing-throws sites across 26 reviewed published runtime files without touching a method body or public signature.
- First segment: 55 sites, 36 methods, 39 new precise annotations. Second segment: 39 sites, 27 methods, 31 annotations.
- All selected implementation symbols are already represented in the pinned 2.1.2 migration change ledger. Validate exact SHA net Psalm reduction, PHPStan/Pint/PHPCS and HTTP/3/Source Audit before closing.

### Batch 35 — first 50-file commit toward zero analyzer findings

- Preserve the published Batch 34 commit as-is. This batch edits **49 disjoint source files plus this tracker** in one commit, rather than overwriting parallel work.
- Match 133 exact outstanding `MissingThrowsDocblock` sites from the Batch 33 PHP 8.5 546-error analyzer to their declaring methods; only touch unchanged files so line mapping remains valid.
- Selected source files are new since the immutable 2.1.2 comparator or already covered by test-backed source-change ledger entries, retaining Source Audit.
- Exception PHPDocs only; preserve production behavior, response trust policies, TLS, protocol logic and public API signatures. Measure *net* Psalm delta before marking verified.
- Continue prioritizing **P0 TaintedFile/TaintedSSRF/TaintedHeader, P1 correctness, P2 design, P3 hygiene** findings and any newly surfaced issue, with no analyzer skips, merge or tag.

### Batch 35 verified analyzer checkpoint

- On `cdff10b13650cba1a818ac0d2185cfe743470b76`, PHP 8.5 Psalm reports **478 errors**: 314 MissingThrowsDocblock, 164 other categories. PHPStan passed, and HTTP/3 8.4 passed.
- Reduction from the Batch 33 546 baseline: **68 net errors**. Remaining security findings include 9 TaintedFile, 6 TaintedSSRF, 3 TaintedHeader.

### Batch 36 — targeted high-volume exception contracts and type correctness

- Change **49 implementation files and this tracker** in a single commit. Match **163 confirmed MissingThrowsDocblock sites** in the Batch 35 PHP 8.5 analyzer to their declaring methods, using explicit exception types.
- Preserve protocol behaviors and response validation; maintain existing public API migration-ledger coverage for published source files.
- Correct nullable address extraction in Happy Eyeballs alternate-family ordering: only append non-null candidates, preserving sequence, address family precedence and bounded list behavior.
- Treat P0 taints, P1 type/state faults, P2 maintenance and P3 hygiene as open until test-backed; no taint escape, blanket suppression, release tag, or merge.
- Verify exact-head 8.4/8.5 Psalm and PHPStan, source audit, PHPForge four QA lanes, HTTP/3, native hosted, Swoole, and docs; record net errors, not just annotations.

### Batch 36 verified analyzer baseline

- On exact SHA `f315ad4e3ae293307650589dbeaaba5df1fce90a`, both PHP 8.4 and PHP 8.5 Psalm reported **381 errors**, including **219 MissingThrowsDocblock** and **162 other errors**. This is -97 since Batch 35 (478 errors).
- PHPStan found two correctness/quality issues in Happy Eyeballs' non-null guard (nested cognitive complexity and redundant null comparison). No analyzer suppressions are acceptable; correct the implementation while retaining its invariant.

### Batch 37 — fifty-file mixed-quality remediation

- This commit covers **49 QA/source/tool files plus this tracker**.
- Remove **25 analyzer-confirmed unnecessary @var annotations** in 16 files (excluding four PHPStan-specific certificate annotations that require a separate cross-analyzer contract review).
- Restore Happy Eyeballs' low-complexity ordering while preserving its nonempty-family invariant via a Psalm-only local type assertion.
- Narrow WebSocket URI scheme through a validated local scalar and eliminate redundant two-pass chunk-extension parsing that Psalm flagged as a possibly-false operand.
- Document **73 confirmed MissingThrowsDocblock locations** across 30 additional source files, retaining exact runtime behavior and ledger coverage.
- Validate both PHP versions, all PHPForge quality lanes, source audit, hosted and protocol CI. Keep P0 file/SSRF/header taint, P1 correctness, P2 maintenance, P3 hygiene and fresh findings open until individually verified. No merges, tags or global suppressions.

### Batch 37 verified analyzer checkpoint

- Exact commit `cd4915a3aceaeafbf0ed34b3e8fac592a92f4d04`: PHP 8.4 and 8.5 Psalm **315 errors** (171 MissingThrowsDocblock, 144 other), net -66 versus Batch 36 381.
- PHPStan reports 22 errors: 21 from three CLI audit scripts losing essential list<string> argument typing when redundant Psalm annotations were removed, plus one over-wide `AdaptivePollStrategy::timeout()` exception tag.
- Security-taint (file, SSRF and headers) remains unresolved and explicitly in scope.

### Batch 38 — CLI contract and exception cleanup

- **50 changed files**: three CLI tools now validate the actual argv list/type contract, `AdaptivePollStrategy` loses an inaccurate @throws declaration, **45 source files** receive precise documented exceptions (approximately 64 logged sites), and this tracker records the scope.
- The helper checks argument values before consumption, without hiding Psalm diagnostics behind tool-specific var annotations or changing the command-line contract for valid callers.
- Confirm exact-head PHPStan/Psalm on PHP 8.4 and 8.5, PHPForge QA, Source Audit, security and protocol tests. Keep all P0/P1/P2/P3 findings open until actually resolved, no silent suppressions, no merge or tag.

### Batch 39 — 50-file exception-contract and source audit reconciliation

- Baseline `c743db86e22cd03c3566ceb35c9fb40f1e18bfb5`: both PHP versions have **291 Psalm errors** (156 missing throws, 135 other); PHPStan and four QA lanes passed, as did HTTP/3. Source Audit failed because `ConnectionTimeouts` had no published migration disposition.
- In one commit change **47 source files**, the API manifest parser, this tracker and the migration ledger: **50 files**. Document 96 reported exception locations at their declaring methods while preserving function bodies and public signatures.
- Add **15 test-linked published migration dispositions**, including the previously missing `ConnectionTimeouts`, without weakening checks.
- P0 file/SSRF/header taint, P1 type/state correctness, P2 maintenance and P3 hygiene remain open. Verify exact-head net Psalm delta, PHPStan and Source Audit before advancing; no merge, tag, or version bump.

- Batch 39 audit correction: the public-property source parser previously included multiline PHPDoc after constructor-promoted parameters until the first method semicolon, falsely changing property fingerprints when adding exception docs. Prevent direct-property patterns from crossing newline boundaries; constructor-promoted properties remain independently parsed from the constructor signature.
- Move `Network/BoundedConnectionPool.php` (one exception site) into the next batch to preserve exactly **50 changed files**.

- Exact first Batch 39 analyzer on PHP 8.4: **261 Psalm errors**, net -30 from the 291 baseline; PHPStan found two inaccurate `InvalidArgumentException` annotations in `AdaptivePollStrategy::timeout()` and `RuntimeOptions::__construct()`. Removed those annotations from the existing Batch 39 commit rather than weakening PHPStan.

### Batch 40 — 50-file exception contract pass

- Latest PHP 8.4 analyzer on Batch 39: **261 Psalm errors**, 103 missing throws and 158 non-throws, net -30 versus Batch 38. Two inaccurate PHPStan exception annotations in Batch 39 were amended out. Source Audit passed with the corrected public-property scanner.
- **48 source files** + pinned 2.1.2 migration ledger + this tracker = **50 changed files** in one batch. Map 84 logged missing throws to their declaring methods without modifying method bodies, signatures, or security validation.
- Add 22 test-linked published comparator source-change dispositions. Preserve strict migration, security and downstream protocol checks.
- Keep top-level executor-worker.php exception and taint diagnostics for a dedicated P0 security/control-flow pass. P0-P3 issues and all additional findings remain open until proven green. No merge/tag/release.

- First Batch 40 analyzer: PHP 8.4 **249 Psalm errors**, net -12 from 261, 91 missing throws. PHPStan found an inaccurate `InvalidArgumentException` constructor declaration in `RuntimeEnvironmentProbe`; this batch removes it while preserving the legitimate `probe()` annotation.

### Batch 41 — targeted P0/P1 correctness and security boundaries

- Exact Batch 40 has 249 Psalm findings on the first PHP 8.4 analyzer; Batch 40's constructor exception correction is awaiting new exact-head analyzer verification.
- Remove reflection of internal supervisor `LogicException::getMessage()` to the control-plane response. Retain `invalid_target` error code while providing a fixed response safe for untrusted callers (P0 tainted downstream URI/data flow).
- Eliminate Psalm's unsupported persistent reference into `ExecutorIpcTrait::$workers`; update worker state explicitly, preserving bounded writes, output retirement, and watcher cancellation.
- Model `stream_select()`'s returned ready-resource array without incorrectly asserting a `list`; continue preserving callback order and stream identity.
- Keep all P0 file/SSRF/header taint findings, P1 lifecycle/type, P2, P3 and additional diagnostics active until their exact-run checks pass; this small critical-fix batch deliberately avoids padding to 50 files with unrelated edits.

- Batch 41 PHP 8.5 Psalm: **245 errors** (net -4 versus Batch 40 249) and **TaintedHeader reduced 3 → 2**; PHPStan identified one redundant `isset` after the known-allocated synchronous worker write. Remove that redundant check; preserve retire-on-write-failure and unchanged worker ownership.

### Batch 42 — native extension object contracts

- Exact pre-batch PR head `1e017a68ddb99a8fb28089ced7b2843e77b924c6`: Psalm PHP 8.4 and 8.5 each reported **245 errors** (91 missing throws, 17 security taints, 137 other); PHPStan, four QA lanes, HTTP/3+QUIC, Source Audit and packaging passed.
- Remove impossible non-object checks following successful dynamic native object construction in the QUIC subprocess and HTTP/3 listener.
- Preserve fail-closed verification of all six required QUIC session methods before ownership transfer. Iterating dynamic method names avoids misleading chained static refinements while retaining runtime checks.
- Preserve public signatures, TLS verification, address policy, worker dispatch and taint configuration. Validate exact-head Psalm, PHPStan, full QA, QUIC and source audit before marking complete.
- All outstanding P0 file/SSRF/header and P1-P3 diagnostics remain open. No merge, tag, or release.

### Batch 42 exact-head QA reconciliation

- On `be0bb8f0d419ba160e1f4a96a81ae79709e77899`, PHP 8.4/8.5 Psalm both report **226 errors** (91 missing throws, 17 file/SSRF/header taints, 118 other): net **-19** from Batch 41's 245. This was a reduction in repeated native-extension object-type contradictions, not a reduction in validated P0 security findings.
- PHPStan exposed a Batch 42 regression in the dynamic QUIC constructor contracts: six QuicWorkerSession method-call/type errors, one executor-worker type/method error cluster and one PhpQuicListener constructor argument issue. All are taken into the next batch before closure, rather than declaring this batch green.
- Accelerated Event Loop PHP 8.4/8.5 jobs failed during Composer dependency acquisition (HTTP/2 504 and curl timeout), not a demonstrated event-loop runtime failure. Re-run on the latest batch SHA to distinguish environmental failures.
- The HTTP/3 + QUIC PHP 8.5 job was still pending during this reconciliation. Do not treat outstanding jobs as green.

### Batch 43 — cross-analyzer correction and fifty-file exception sweep

- **Exactly 50 files in one atomic commit**: 45 production/source/worker files (43 files receiving exception contracts, including executor-worker, and two dedicated native-object type-guard files), three targeted tests, one pinned 2.1.2 migration ledger and this tracker.
- Source exception work maps **81 previously reported MissingThrowsDocblock locations** to relevant throwing methods where the implementation already throws or forwards the exception. No method bodies, public signatures, response trust behavior or protocol framing changes are made for those contract-only files.
- Reconcile native-extension PHPStan by restoring the runtime object-type validation at PhpQuicListener and executor-worker, and by using independent runtime method checks for all six QuicWorkerSession required capabilities. Preserve the verified native TLS/ALPN and close-on-error path, avoiding security bypasses and global suppressions.
- Add adversarial tests for development-watch maxFiles containment, finite/positive/upper diagnostics thresholds, and request resetter registration capacity. Add **9 test-linked source-change dispositions** for previously untouched 2.1.2 published types while preserving comparator strictness.
- Deliberately defer empty-constructor inferred throws in RuntimeOptions, RuntimeCapabilities, RuntimeEnvironmentProbe, and AdaptivePollStrategy because PHPStan has previously rejected inaccurate constructor/timeout tags. Analyze the real exception execution contract separately rather than inventing throws.
- **Verification pending:** require both Psalm versions, PHPStan, all PHPForge QA lanes, Source Audit, HTTP/3, Swoole, host integrations and docs to report on the exact new SHA; record net diagnostics and fix each regression before considering this batch closed.
- Remaining P0: 9 file, 6 SSRF and 2 header taint reports remain open; no taint suppression, disablement, merge, tag, or version bump.

### Batch 43 corrective QA pass — PHPDoc parser safety

- The first Batch 43 Source Audit failed immediately on a PHP syntax error in `Http3ClientSiblingManager.php:469`: an exception tag was placed before an existing one-line PHPDoc. This also surfaced as HTTP 500 in Swoole and blocked downstream hosted/QUIC acceptance; these runs do not establish runtime defects.
- Reviewed all 45 implementation/worker files touched by Batch 43. Repair **seven one-line PHPDocs** where the inserted exception tag appeared outside the comment, and normalize the indentation of newly inserted `@throws` tags to the containing PHPDoc block.
- Preserve method signatures and bodies, native capability validations, public contracts, and all taint checks. This is a parser/style correction only; code behavior is unchanged.
- Require new exact-head Source Audit, Swoole, HTTP/3+QUIC, PHPForge QA, both Psalm versions and PHPStan before declaring verified. Keep all P0 security and remaining P1-P3 diagnostics open.

### Batch 43 corrected-head verified baseline

- Exact SHA `801a630c8dbf1ea9a84a929e44ecbee3f6dae453`: PHP 8.5 Psalm reports **225 errors** and 153 informational findings, including **86 MissingThrowsDocblock**, **9 TaintedFile**, **6 TaintedSSRF**, and **2 TaintedHeader**. Total net reduction from Batch 42's 226 was only one, as documenting throw boundaries propagated obligations into their callers.
- PHPStan reports exactly two errors: `QuicWorkerSession` class cognitive complexity 84 (limit <80) and `connect()` complexity 14 (limit <12). Remaining tested host/source/protocol/QA lanes that completed passed; HTTP/3 PHP 8.5 was pending at review.
- Reconcile actual analyzer sites before claiming successful exception remediation. Do not count annotated source locations as removed errors.

### Batch 44 — constrained correctness and analyzer convergence

- Collapse the QUIC native capability check into one bounded `array_any` validation of all six required methods; preserve the runtime object guard and failure behavior. Follow with narrowly scoped per-method assertions whose runtime guarantees are already enforced by that validation, allowing PHPStan to understand the dynamically loaded extension while reducing class/function complexity.
- Consolidate the `PhpQuicApi` class and method probe into one pass that uses `class_exists` to validate each dynamic class before `method_exists`. Retain all extension/event requirements and initial fail-closed checks.
- Correct WebSocket URI parsed-array shape documentation without changing parsing or validation, and avoid unsetting Swoole's unused callback argument. Keep callback signature and worker lifecycle unchanged.
- In two independent HTTP/2 reference tests, separate the OpenSSL CSR's by-reference private key parameter from the originally validated signing key, removing four misleading PHPStan-only annotations. Preserve the actual certificate/key identity and cryptographic validation.
- This targeted batch changes six implementation/QA files and the tracker rather than padding to fifty. Require exact SHA Psalm, PHPStan, PHPForge QA/protocol and source audit verification. No suppressions, weakened taint policy, merge, tag or release.

### Batch 44 exact-head analyzer and QA reconciliation

- On `62adc39e557ac634623582fabe8048fd85c76612`, PHP 8.4 and 8.5 Psalm each have **218 errors**, net **-7** from Batch 43 (225). PHPStan passes without complexity or dynamic-call errors. The **17 P0 taint reports** remain unchanged and open.
- PHPForge quality lanes failed due to precisely two `no_extra_blank_lines` Pint warnings in independent HTTP/2 reference fixtures and one PHPCS unused first callback parameter warning in the Swoole worker-start hook. Pest, Rector, reference integrity, syntax, duplicates and comment policy passed; handle quality regressions before progressing.
- Preserve full security checks, QUIC extension capability enforcement and the existing native host runtime acceptance.

### Batch 45 — strict-QA remediation and API tooling contracts

- Restore single blank-line spacing in both independent HTTP/2 RSA fixture checks without changing certificate/key material or TLS assertions.
- Replace the Swoole worker-start callback's unused first formal parameter with an explicit variadic native-event argument reader and strict optional worker-id validation. Preserve worker-id nullability and callback timing; fail closed for malformed dynamic extension arguments instead of silently accepting them.
- In the source-only public API diff audit, iterate candidate symbol names without an unused value binding and model the comparator input as validated string-key maps, allowing the existing runtime string checks to handle flexible record shapes.
- In the public API manifest generator, confirm all three required argument offsets before indexing and initialize the public property inventory in all control paths. Keep its manifest schema, SHA and source-audit contracts intact.
- This batch touches five implementation/QA/tool files plus the tracker. No analyzer ignore rules, taint escapes, releases, tags or merges. Validate both Psalm/PHPStan versions, all four QA matrices, source audit, hosted and HTTP/3 integration on the exact new SHA and record net error change.

### Batch 46 — 50-file Psalm correctness and exception round

- **Baseline:** exact PR head `c0f81a8849cddd7140d4bc8a99192e877299efed`, PHP 8.4 and 8.5 both **213 Psalm errors**. The independent PHPStan quality gate reported **4 errors**: three redundant `isset` offset checks in the API manifest CLI and Swoole configure callback complexity. Both remain release blockers until reanalyzed.
- **File count:** exactly **50** changes in one commit: **48 source/CLI files**, this plan, and the pinned migration ledger. Do not split into per-file commits, pad with unrelated edits, merge or tag.
- Map all **86 reported `MissingThrowsDocblock` call-site diagnostics** in **33 declaring files** to approximately **60 unique method/exception declarations**, preserving adjacent existing `@param`, `@return`, inline docs, and validated code paths. These are documentation of already possible exceptions, not intentional new exceptions; the next Psalm pass must measure net propagation into callers rather than counting annotated sites as resolved.
- Correct CLI manifest minimum argument cardinality before accessing indexed arguments, rather than asserting offsets PHPStan has already proven within the later check. Preserve the same usage error and public API source audit schema.
- Keep platform-optional false-on-unsupported-PCNTL handling in the child reaper through one narrow type-normalizer, consistent with the PHP 8.4/8.5 `int|false` contract. Retain anomalous `waitpid` status rejection.
- Remove unneeded watcher placeholder assignments, redundant WebSocket status cast, clarify DNS cancellation precedence and explicit idle-pool null handling, and reuse the immutable relay cancellation token/deadline for lifecycle subscriptions.
- Normalize dynamic TLS ALPN metadata as a string-keyed optional extension map; preserve peer verification, certificate checks, stream-context failure guards, and user-provided-warning reporting.
- Remove misleading `@param resource` tags from mixed-input guards in stream metadata, response transfer, reactor watch registration and TCP listener. Preserve the actual `is_resource`/type validation because streams may be closed or malformed at runtime.
- Add a test-linked pinned 2.1.2 migration disposition for `RuntimeCapabilities`; all other already-published source files in this batch are covered by the existing ledger.
- **Unresolved:** P0 remains 9 TaintedFile, 6 TaintedSSRF, 2 TaintedHeader (17 reports); no global suppressions or disabling taint analysis. Other type/lifecycle, exact-sha CI, review threads, strict QA and security validation stay open. Confirm full PHP 8.4/8.5 Psalm and PHPStan as well as PHPForge QA, Source Audit, HTTP/3+QUIC, Swoole, docs, packaged install and host integration on the new head before closing.

### Batch 46 — 50-file Psalm remediation and cross-analyzer regression correction

- **Pre-batch exact PR head:** `543b72cf836cffc836cc5fe367a10bf5f4ffc299`; Psalm PHP 8.4 and 8.5 each reported **193 errors**, of which **86 were MissingThrowsDocblock**, and P0 taint findings remain open. This is an error count, not an estimate of remaining development time.
- Commit one coherent **50-file round**: 35 implementation files with verified reported exception call sites, eight additional source files addressing PHPStan/Pint regressions and analyzer-clean runtime boundaries, six regression-test files exercising native/host capability defaults, QUIC/QPACK preambles and runtime detection, plus this tracker. No unrelated files added solely to increase count.
- Preserve dynamic callback, TLS and QUIC runtime checks. Replace invalid `@throws` tags on the three empty/default-constructor wrappers and adaptive poll method where PHPStan confirms those methods do not throw; retain the corresponding Psalm reports as unresolved until analyzer-compatible designs are found.
- Replace two overbroad TLS metadata annotations with `array_key_exists` guarded ALPN reads; use an extracted Swoole WorkerStart callback to reduce complexity without changing worker startup sequence or worker-id validation.
- Restore ChildReaper private-member ordering for Pint and remove a dead catch-path assignment. Declare propagated exception contracts only at logged method boundaries, without global taint suppressions or coverage changes.
- **Verification mandatory:** inspect full Source Audit, PHPStan, both PHP 8.4/8.5 Psalm histograms, all four QA matrix jobs, Swoole/HTTP3 and packaging at the new exact SHA before closing. The 35-file exception pass may propagate documented throws into callers; only count a net drop after analysis.
- Continue P0 **TaintedFile, TaintedSSRF, TaintedHeader** investigation, non-documentation type/cancellation/resource lifecycle diagnostics and any worker findings after this batch. PR stays draft, unmerged, untagged.
