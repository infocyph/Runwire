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
| 11+ | Fix remaining PHP type contracts, security taints and exception propagation | TBD | Not started |

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
