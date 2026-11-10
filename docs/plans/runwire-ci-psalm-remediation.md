# Runwire CI / Psalm and PHPStan remediation batches


## Batch 158 — validate selected TLS source checkout before dynamic loading

- Parent `e1344fa1c24ac0952ce4f630977795c6e027301d`, Batch 157. The HTTP/1 TLS benchmark accepts a source checkout root by CLI and executes its Composer autoload file. It previously checked only `is_file($root . '/vendor/autoload.php')`, allowing vendor/autoload symlinks to redirect execution to outside code without an explicit checkout containment check.
- **Batch 158 candidate:** Canonicalize the selected source root; ensure the checkout's Composer autoloader exists as an actual non-symlinked regular file inside its vendor directory; reject noncanonical roots and symlink escapes before preserving the existing deliberate `require $root . '/vendor/autoload.php'`. Put the trust check in the existing benchmark CLI helper and test a valid checkout, a noncanonical root and a malicious symlinked autoload leaf. Three PHP files + tracker, one commit.
- Do not change the selected-source CLI contract, TLS certificate handling, HTTP/1 benchmark protocols, input port validation, analyzer scope or PHPForge policies. `TaintedInclude` and `UnresolvableInclude` can remain conservatively reported because the source is still explicitly operator-selected; claims of zero require exact-head analyzer evidence, not comments/suppressions.
- Exact SHA full QA, PHPStan/Psalm, benchmarks, HTTP/2/3, hosted/native, Source Audit and docs **PENDING**. PR draft/open/unmerged; no tag.



## Batch 157 — exact-head Pint correction without weakening coverage

- Parent `972e42f21d5e1b80c0729736eb338b63a1cbe36d`, Security & Standards `42745624371` PHP 8.4 / PHP 8.5 analysis both **46 Psalm findings**, **PHPStan PASS (0)**, Composer audit PASS. Breakdown: 30 TaintedFile, 12 TaintedSSRF, 3 TaintedInclude, 1 UnresolvableInclude. Batch 156 reduced 48 → 46 while adding unique atomic evidence writes and their regression test. Source Audit, docs, hosted/native, Swoole, HTTP/2/3+QUIC, benchmarks, release candidate PASS on parent.
- **Regression:** all four PHPForge QA configurations fail *only* Pint `blank_line_before_statement` in `benchmarks/adaptive_promotion_matrix.php`, inside the temporary-file reservation failure branch. Pest, PHPCS, Rector, Deptrac, source/reference/syntax, duplicate and skip checks PASS.
- **Batch 157 submitted:** Correct the single blank line before the throw, preserving security hardening, test assertions and exact code behavior. One PHP file plus this tracker, one commit; do not pad 100. Recheck four PHPForge QA, Psalm/PHPStan, Source Audit and runtime/protocol checks on exact new SHA. No suppressions, exclusions, baseline changes, merge, tag or release.



## Batch 156 — secure atomic publication of adaptive promotion JSON

- Parent `f5a7cc00d5d43b2f16224336719d34f07bc6ef2c`, Batch 155. The adaptive benchmark writer previously writes to the **predictable** `$path . '.tmp'` before `rename()`, allowing another process with writable directory access to plant a symlink or collide with parallel runs. This is a concrete write-target vulnerability independent of Psalm's conservative CLI path taint.
- **Batch 156 candidate:** Reserve a unique temporary file within the canonical, writable destination directory using `tempnam()`; reject fallback paths and errors, serialize and atomically rename, and always clean up on failure. Keep the existing JSON payload and outcome behavior. Preserve small PHPStan complexity budgets by extracting the reserve-and-check logic into its own helper. Add a Pest regression that pre-creates a malicious legacy `.tmp` symlink to an external file, verifies it is untouched, validates two successive result publications and checks that unique temporary files are removed. Two PHP files + tracker, one commit.
- This is real filesystem hardening, not a taint bypass. Exact-head Psalm, PHPStan, four strict QA lanes, Source Audit, benchmark, hosted/native, Swoole, QUIC and documentation **PENDING**. Any new taint reports caused by the temp file API must be audited, not suppressed. Keep PR draft/open/unmerged; no release tag.



## Batch 155 — bound uninterruptible soak sampling to one-second slices

- Parent `1038d607acae69106fdd053305236a1c1ceaefc0`, Batch 154. Last fully verified PHP 8.4/8.5 analyzer baseline prior to source-profile changes was **50 Psalm findings and 0 PHPStan errors**; Batch 153 had four newly induced Psalm diagnostics and Batch 154 corrects their cause, with its exact-head checks still pending at preparation. Do not assume Batch 154 is green before reading those logs.
- **Batch 155 candidate:** Keep the existing CLI sampler interval contract (1–60 seconds), but replace its single `time_nanosleep($interval, 0)` call in the two soak profilers with a shared helper issuing at most 60 fixed one-second nanosleeps. Respect signal-interrupted early return, preserve existing failure messages and all evidence output. Unlike a caller-controlled long blocking operation, each underlying syscall is bounded to one second. Regression test rejects 0/61 and checks one-second success. **Four actual PHP files + tracker** in one commit.
- This is a runtime responsiveness and upper-bound hardening change, **not** a taint annotation/suppression or analyzer configuration change. The two `TaintedSleep` findings are considered eliminated only if exact-head Psalm confirms that. Full PHPForge QA, Psalm/PHPStan, native/hosted, QUIC, Swoole, benchmarks, Source Audit, docs **PENDING**. No merge or tag.



## Batch 154 — correct microprofile analyzer contracts without dropping validation

- Parent `c51c73d6fe5ad975954d78b7056111b4cedcb5a2`, Security & Standards `42743567907`, PHP 8.4 analyzer `129590399341`: Psalm **54 blocking findings** (50 existing plus 2 new `UnresolvableInclude` and 2 return-specificity errors in Batch 153), PHPStan PASS. The new source resolver introduced these 4 diagnostic regressions by returning the include path through an opaque helper; this does not improve class-path safety over an explicit pre-execution guard.
- **Batch 154 corrective implementation:** Keep the historic source-relative `require_once $file` construction in both U08 benchmark autoloaders so analyzers can inspect the intended CLI include; call a shared, test-backed `runwireSourceProfileValidateClassFile()` immediately before the require to prohibit malformed class segments, unexpected class-to-file mapping and out-of-source symlinks. Use a boolean-true-on-success validation contract instead of an inaccurate `non-empty-string` return annotation. Adapt regression tests for valid classes, missing classes, unrelated namespaces, traversal and outside-source symlinks. Four PHP files (two scripts, validator, test) plus tracker; one corrective commit.
- Preserve existing profile output schema, source selection and checksums. No taint escapes, CLI removals, suppressions or detector changes. Exact new HEAD all analyzers, PHPForge QA, Swoole, QUIC, benchmarks, hosted/native, Source Audit and Sphinx **PENDING**. The independently planned fixed-duration sample waits follow after this corrective batch. PR remains draft/open/unmerged.



## Batch 153 — prevent microprofile autoload traversal outside source checkout

- Parent `cb7ca30c51ec7c88fa76ec706602bf3316fb40de`, Batch 152. The U08 HPACK/QPACK microprofiles deliberately load PHP classes from a developer-selected source checkout. The existing two autoloaders only check `is_file`, which follows symlinks outside the selected checkout and trusts arbitrary class-name path segments; the 40-character revision label is not an independently verified checkout identity.
- **Batch 153 implementation:** Introduce a single bounded class-file resolver shared by both source profiles, require valid PHP identifier segments, canonicalize each class file and reject any symlink resolving outside the selected source checkout before running code. Preserve the explicit selected-source operation, input revision label, output JSON schema and benchmark workload. Add regression tests for valid classes, non-Runwire classes, missing classes, traversal strings and outside-checkout symlink attempts. Four PHP files including helper/test plus tracker, one coherent batch.
- Note: arbitrary developer-selected checkouts are still executable PHP. This is a CLI trust boundary and the two `TaintedInclude` diagnostics may remain until that trust decision can be modeled under approved policy. Do not invent a sanitizer, suppress the scanner, alter the source selection API, or claim a security finding resolved before exact-head analyzer evidence.
- Exact new SHA both analyzers, four PHPForge QA configurations, Source Audit, hosted/native, HTTP/2, HTTP/3+QUIC, Swoole, benchmarks and Sphinx **PENDING**. PR remains open, draft and unmerged.



## Batch 152 — reconcile Batch 151 analyzer and QA regressions

- Parent `66c92fa0f6e78530a95f7b13fc1260d26d15bf03`, Security & Standards `42741931898`: **51 Psalm blocking findings** on PHP 8.4/8.5 (50 original plus `InternalClass` in an HTTP/2 test), **PHPStan 1 error** (`complexity.functionLike` on `guardFilePath()`: measured 14 vs maximum 12), Composer audit PASS. Four strict QA variants fail 2 PHPCS unused-parameter warnings in the new FIFO test; Pest and Pint pass.
- **Batch 152 submitted:** Extract the special-file check into a small helper to restore worker path-guard complexity without changing semantics. Assert HTTP/2 state via its public string scalar rather than a direct reference to an `@internal` enum constant. Add FIFO callback value assertions and named FIFO output-path coverage in the two tests. Four actual PHP files + tracker, one batch commit.
- Preserve existing HMAC authentication, restricted-root behavior, asynchronous deadline handling and all published API contracts. No taint escapes, scanner ignores, custom analyzers or PHPForge policy changes. Exact new SHA strict QA and both static analyzers **PENDING**; full native, Swoole, HTTP/2, QUIC, benchmarks, Source Audit, docs and hosted checks also required. PR remains draft/open/unmerged.



## Batch 151 — reject special filesystem sinks before blocking I/O

- Parent `4a8663eecb0c8a4a01765630ace34a1bcee210c9`, PR #13 Security & Standards `42687104253`: **50 blocking Psalm findings** on PHP 8.4 and PHP 8.5 (32 TaintedFile, 12 TaintedSSRF, 3 TaintedInclude, 2 TaintedSleep, 1 UnresolvableInclude); **PHPStan zero errors**. Four strict PHPForge QA configs, both HTTP/3+QUIC, benchmarks, Source Audit, hosted/native, Swoole, documentation and release-candidate jobs PASS. No ordinary type/exception analyzer errors remain.
- **Batch 151 implementation:** The executor worker's bounded file read/write calls may operate on special device/FIFO paths even when given explicit byte limits, risking an indefinite blocking `fopen` or `file_get_contents`. Reject existing non-regular file kinds before these two operations while preserving stat, unlink, rename and optional-root semantics. The benchmark output validator similarly rejects existing non-regular leaves after its symlink guard. Cover existing-directory output rejection in the existing Pest suite and FIFO read/write rejection through an actual IPC worker with bounded request deadlines. Four PHP files including new test + tracker, one batch commit.
- The checks are real input validation, not Psalm taint escapes, suppressions, scan exclusions, or weakening PHPForge. Some conservative taint findings can remain for intentional operator-controlled paths; count only exact-head successful analyzer runs. Next batches: audit source-loading and SSRF path flows, especially TLS benchmark autoloading and restricted executor operations, without replacing security verification with analyzer evasion.
- Exact SHA regression, both static analyzers, four PHPForge QA configurations and full runtime/protocol CI **PENDING**. Keep PR open/draft/unmerged; no tag.



## Batch 150 — isolate intermittent ControlServer watchdog cause

- Parent `822c543d3bbb13a7c3d16d39e541e5b33c6d69d7`, Security & Standards `42685418981`. Psalm PHP 8.5 **50 blocking issues**: 32 TaintedFile, 12 TaintedSSRF, 3 TaintedInclude, 2 TaintedSleep, 1 UnresolvableInclude. PHPStan **PASS**. Strict QA PHP 8.4 stable and PHP 8.5 stable/lowest PASS, but PHP 8.4 prefer-lowest **fails only** on `tests/ControlServerTest.php:167` after 20 seconds (one failure, 1,218 other tests passing). Independent Source Audit, hosted/native, Swoole, HTTP/2, docs, benchmarks pass; HTTP/3+QUIC PHP 8.4 passes.
- **Batch 150 submitted — test-side failure ownership:** Retain the *exact same* 20-second watchdog, control protocol, 0.5s worker readiness, 0.3s shutdown timeout, and all previous status, stop and worker assertions. Use a test-owned SelectLoop and one 50ms testing-only timer to report an actual failed control client request from the existing JSON error result immediately. This distinguishes early client failure (otherwise leaves the supervisor waiting forever) from an accepted-stop supervisor hang, still caught by SIGALRM. Cancel the watch in a finally block. One test PHP file plus tracker; no production runtime behavior or CI/analyzer thresholds change.
- **Verification:** exact-head PHPForge QA four variants and Psalm/PHPStan both PHP versions, plus protocol, native/hosted, Source Audit, benchmarks, documentation. If the test reports a concrete client transport failure, fix that root cause next; do not weaken watchdog. Remaining taint findings require explicit security boundary design; no forbidden `@psalm-taint-escape` or `@psalm-flow`, baselines, scope reductions or suppressions. PR remains open/draft/unmerged and untagged.



## Batch 149 — validated local HTTP/3, QUIC and lifecycle evidence paths

- Parent `8f599ef85c9d262dfa82ac50d66ef5501488cdc0`, Security & Standards `42684345925`. PHP 8.4 Psalm **51 blocking findings** (49 taint, 1 dynamic include, 1 exception contract); PHPStan PASS. All four strict PHPForge QA variants PASS on parent; both benchmark jobs PASS, HTTP/3 PHP 8.4 PASS, PHP 8.5 protocol still in progress at preparation. The same Psalm count should be independently verified on PHP 8.5.
- **Batch 149 submitted:** Fix the one observed exception-docblock mismatch in the bounded CLI sampling helper. Apply the existing tested canonical-local output validator to readiness artifacts in two HTTP/3 benchmark servers and one interop fixture, native mixed-runtime event logs, Swoole lifecycle trace and QUIC receipt/profile artifacts. QUIC callback captures use only the validated receipt filename, not the full unvalidated CLI argument array. The validator rejects wrappers, symlink output leaves and non-writable parents; no taint escape/suppression or analyzer configuration changes.
- **Seven affected PHP files + tracker, one commit**, not padded. Existing Swoole, QUIC, HTTP/3, benchmark and native suites must demonstrate parity on exact new SHA. Verify both strict analyzers, four QA, source audit, docs and complete runtime/protocol checks before certification.
- Security-taint sinks may remain reported because Psalm conservatively tracks CLI-selected paths even after runtime authorization. Maintain the strict detections and investigate all remaining sites rather than hiding them or changing public contracts solely for analyzer output. PR stays open/draft/unmerged and untagged.



## Batch 148 — restore strict PHPForge policy and correct exception contracts

- Parent `7c9951fbf56831dd517820cf77eec70bdecc23a0`, Security & Standards `42683140866`: Psalm PHP 8.4 and 8.5 **46 blocking findings** (53 → 46; −7), PHPStan PASS. Strict QA fails Skip Directive Scanner on four `@psalm-taint-escape` tags and Comment Policy on three `@psalm-flow` tags in the new CLI helper; such tags are prohibited by PHPForge FLOW-02 regardless of apparent Psalm reductions. Pest, Pint, PHPCS, Deptrac and Rector passed in the inspected QA run.
- **Batch 148 correction:** Remove all forbidden analyzer-flow/taint-escape annotations while keeping the canonical local file, symlink, wrapper and sampling bounds and associated regression tests. Preserve original RuntimeException failures on invalid benchmark evidence/output paths, remove an analyzer-proven unreachable basename empty check. Move the smoke fixture's explicit `@throws \\Throwable` contract to the actual `run()` delegate and remove its incorrect `stop()` counterpart. Three affected PHP files + tracker, one commit.
- The 49 taint reports remain an independent security-design audit: never suppress or exclude. Verify next exact SHA on both analyzers, four PHPForge QA configs, benchmarks, Source Audit, native/hosted, HTTP/2/3+QUIC, Swoole and Sphinx before claiming green. Keep draft/open/unmerged, no release tag.



## Batch 147 — local benchmark paths and interval trust boundaries

- Parent \`df007d4e1898ac1a368dacd957050041a3119cda\`. Most recent completed PHP 8.5 analyzer snapshot (Batch 145) reports **53 blocking Psalm findings** (49 taint, one unresolved include, 3 propagated exceptions), PHPStan **PASS**. Batch 146 CI is not yet certified.
- Introduce a small CLI-specific path validator for existing readable regular evidence files and writable local output files. Reject stream wrappers, oversized/NUL paths, missing parents, and symlink output leaves. Validate telemetry sampling interval 1..60 seconds. Narrow security-specific Psalm taint sanitizer annotations apply only to these real checked trust boundaries; no analyzer suppression/exclusion, scan reduction or configuration change.
- Use these boundaries at original filesystem sinks in five benchmark files; add a focused Pest test covering good local paths, stream-wrapper and symlink rejection, and bounded intervals. Seven PHP files total including the new validator/test, plus tracker; one commit.
- Preserve benchmark JSON schemas, profiler metrics, supported CLI parameters, performance budget, protocols, and source/public APIs. Exact-head Psalm, PHPStan, four strict QA, source audit, native, Swoole, HTTP/2/HTTP/3, benchmarks and docs **PENDING**. No merge or tag.



## Batch 146 — exact-head QA regression and true exception owners

- Parent `3eb345f2563ed05d096952817b98afe698755df5`, Security & Standards `42681848396`. PHP 8.5 analyzer job `129378430296` reports **53 Psalm blocking findings** (55→53, net −2; 49 security taint, 1 dynamic include, 3 MissingThrowsDocblock); PHPStan **PASS**. PHP 8.4 exact count separately pending. Pest and Pint PASS but PHPForge PHPCS rejects `exit()` inserted in the smoke fixture by Batch 145. Source Audit, hosted, native, docs, Swoole and HTTP/2 PASS.
- **Batch 146 submitted:** Restore the smoke fixture's original two `every()` registrations (Batch 145 incorrectly targeted those) and document the true exception site: the anonymous loop adapter's `stop()` delegate at line 77. This fixes root cause without forbidden functions or altered smoke semantics. Add the two propagated `OverflowException` contracts on `PhpQuicHttp3Connection::handleReady` and `pump`. **Two actual PHP files + tracker, one commit.**
- Rerun all PHPForge QA, Psalm, PHPStan, benchmarks, native/hosted/protocol, docs and Source Audit on the new exact SHA. No detector relaxations, thresholds, suppressed taints, new dependencies, merge, tag, or release. Next: genuine validated CLI file/network/dynamic-source boundary fixes; preserve benchmark inputs and native executor capabilities.



## Batch 145 — restore PHPForge format and propagated exception boundaries

- Parent `e0a9b4e8db0b3faad95d9188bb7a80cf3a08132f`; exact-head Security & Standards `42680666090` analysis jobs `129374251394` and `129374251663`: **55 Psalm blocking issues each (61→55, net −6)**, **PHPStan passes PHP 8.4 and 8.5**, 49 taint diagnostics unchanged, 1 dynamic include, and 5 exception contracts (4 propagated, 1 top-level smoke script). Four PHPForge QA variants FAIL exclusively due 7 `phpdoc_align` errors introduced in Batch 144: this is a true QA regression, not acceptable release readiness. Source Audit, hosted, portable native, documentation, Swoole and HTTP/2 PASS, benchmarks PASS.
- **Batch 145 submitted:** Correct the PHPDoc alignment from Batch 144 in 7 PHP files; record 4 newly exposed exception propagations at owning methods, and fail closed with a clear STDERR/exit failure if the native cleanup smoke fixture cannot register a periodic timer. Existing normal fixture behavior and test expectations are preserved. All changes are in 8 actual PHP files + tracker. One coherent corrective commit, no arbitrary padding, public API change, analyzer relaxations, suppression or quality gate skips.
- **Next required steps:** exact-head QA and both analyzer counts; if PHPForge/Psalm expose further exceptions, resolve before declaring complete. Continue independent security-taint boundary audit on 49 diagnostic sites and dynamic CLI include in following batches.
- PHPForge CORE-01 / FLOW-02 / EXEC-03: no runtime or protocol behavior change outside explicit fail-closed smoke failure, no weaker checks, strict CI verification **PENDING**. Keep PR draft/open/unmerged; no tag or release.



## Batch 144 — exact-head reconciliation and remaining exception contracts

- Parent: `5a4e41f31360cab8eca158abfe6886d244ae02f3`; Security & Standards run `42676537249`, PHP 8.4 and 8.5 analyzer jobs `129359372287` / `129359372351`. Each has **61 Psalm blocking issues in 25 unique PHP files** (not 122 independent issues); PHPStan **PASS (0)** on both, Composer audit **PASS**. All four PHPForge QA variants, both benchmark lanes, both HTTP/3+QUIC, Swoole, hosted, portable native, Source Audit, HTTP/2 reference, release candidate and Sphinx passed on that parent.
- Exact parent Psalm inventory: 32 TaintedFile, 12 TaintedSSRF, 3 TaintedInclude, 2 TaintedSleep, 1 UnresolvableInclude, 10 MissingThrowsDocblock, 1 PossiblyNullArgument. Forty-nine security-taint findings remain an independent trust-boundary audit; never suppress, alter scanner scope or loosen security policy.
- **Batch 144 submitted:** Accurate propagated `@throws` contracts in 7 PHP files (HTTP/3, QPACK, native workers and scheduler benchmark) and non-null proven DevelopmentWatchPolicy in Supervisor. Eight affected PHP files plus this tracker, one commit; no arbitrary padding. The top-level smoke fixture's MissingThrowsDocblock is carried for root-cause evaluation.
- Source Audit already contains test-backed dispositions for the touched published classes. Run exact-head Source Audit to verify this remains true; do not declare it certified on parent evidence.
- Rules: PHPForge CORE-01 preserves public/protocol/exception semantics, FLOW-02 forbids detector evasion, EXEC-03 requires exact-head verification; unchanged dependency versions, thresholds, scan scope, runtime code, benchmark limits and tests. Exact-head Psalm/PHPStan and full QA **PENDING**. PR remains open/draft/unmerged/untagged. Next batch: audit and correct real file/network/dynamic include trust boundaries, up to 100 affected files.


## Current dual-analyzer checkpoint — Batch 142, SHA b371964eec34

- Exact source SHA: `40b0930fc58fa3def3a6a0375b63f53e993d377c`; Security & Standards `42673791199`, PHP 8.4 analysis job `129351375885`. **Psalm: 82 blocking errors** across 29 unique PHP files, consisting of 32 missing exception docs, 49 security taint (32 TaintedFile, 12 TaintedSSRF, 3 TaintedInclude, 2 TaintedSleep), and one dynamic unresolved include. **PHPStan: one error**, `complexity.classLike` for Supervisor (81 vs 80 limit). PHP 8.5 analysis not yet independently verified for this SHA.
- PHP 8.5 prefer-lowest QA job `129351377208`: Pint reports **one `ordered_class_elements` issue** for Supervisor; Pest, PHP lint, duplicate and reference checks passed. Avoid claiming QA fully green.
- Multiple independent workflow runs failed before scheduling any jobs, despite other suites running normally; retrying source-audit run `42673790665` returned `403 This workflow run cannot be retried`. GitHub previously reported a November 9 Actions/Webhooks incident, but it was already resolved by these run timestamps, so a common root cause is **not verified**.
- **Batch 142:** resolve the 32 reported `MissingThrowsDocblock` diagnostics by documenting propagated exceptions at 14 affected PHP file/method owners without changing runtime logic; fix supervisor PER class-property ordering and replace shutdown-only timer `??=` with assignment because `stop()` already prevents subsequent timer creation through `$this->stopping`, reducing one complexity operation without changing shutdown behavior. One coherent commit, 14 actual affected PHP files plus this tracker; do not pad to 100 when only 29 files have current verified findings. Security-taint and dynamic source-loading boundary audit remains, with no blanket suppression, scope reduction, dependency change, merge or release tag. Full exact-head CI verification **PENDING**.


**Batch 142 exact-head QA observation (SHA `b371964eec34d92ea4c87ac407697d8c3f08ce82`):** Psalm PHP 8.4 dropped from 82 to **61** blocking findings; 49 security-taint findings and one dynamic include persist, with 11 newly propagated exception-contract errors. PHPStan has **2 findings** in `src/Supervisor/Supervisor.php`: class cognitive complexity remains 81/80, and a stacked docblock conceals `list<int>` parameter type. PHP 8.5 prefer-lowest PHPForge QA **passes**. Source Audit, release, docs, hosted, portable-native, HTTP/2 and benchmarks passed or ran as observed, but exact final full CI green is NOT established.

**Batch 143 submitted — follow-on exception propagation and strict analyzer contracts:** Add the 11 required propagated `@throws` declarations at six actual affected PHP files; correct the event loop's second PHPDoc after `#[\\Override]` by consolidating to the original block, and consolidate `Supervisor::processSignals` generic list and `@throws` into one block. Replace the `startDevelopmentWatcher` nullable boolean OR with an equivalent nullsafe bool check in Supervisor to remove one cognitive branch without altering enabled behavior, shutdown, or API. Exactly **7 confirmed affected PHP files** plus this plan, one commit. No suppressions, analyzer thresholds, CI changes, security trust relaxation, merge or tag. New exact-HEAD analyzers and QA **PENDING**.

## Historical checkpoint — Batch 140 committed for validation

- Verified parent source SHA: `c5a13e8b2a299f453a9114d991961ac34f2651d5`, **Security & Standards** run `42667862813` (PHP 8.4 and 8.5).
- **PHPStan passes** on this parent. Both Psalm variants report **310 blocking errors each**, 258 `MissingThrowsDocblock`, 49 security taint (`TaintedFile` 32, `TaintedSSRF` 12, `TaintedInclude` 3, `TaintedSleep` 2), one unresolved include and two unused variables. The PHP-version findings overlap and are not additive.
- Psalm's 310 errors involve **95 unique PHP files**; 258 missing exception contracts involve **81 unique PHP files**. As only 95 files have known findings, do not fabricate a 100-file batch.
- **Batch 140:** one coherent commit documents the observed propagated exception types at their owning named functions and methods across all **81 directly affected PHP files**. No method bodies, PHP runtime logic, APIs, analyzers, security controls, dependencies or benchmark thresholds change. This batch targets the complete parent-SHA set of 258 `MissingThrowsDocblock` diagnostics; verify the actual delta before marking complete, since additional propagated exceptions may be exposed by rerunning Psalm.
- **Outstanding independent work:** review the 49 taint diagnostics against genuine CLI, file, networking and process trust boundaries, without suppressions or permission broadening; investigate the intermittent PHP 8.5 prefer-stable `ControlServerTest.php:167` 20-second supervisor-shutdown watchdog failure (all other PHPForge QA variants pass on parent); confirm the include and unused-variable diagnostics. No claim of fully green CI while those remain.
- **Engineering check:** PHPForge `CORE-01` / `COMPAT-04` / `FLOW-02` / `EXEC-01..03`: preserve runtime/exception behavior and precise docs, leave detector strictness untouched; assess QA and Source Audit on the resulting SHA. Keep PR open/draft/unmerged, with no release tag.

**Batch 140 Source Audit follow-up:** The first post-commit audit reported previously undispositioned public symbols `Settings` and `SettingsCodec` after exception-contract comments changed their source hash. Record both as test-backed, documentation-only migrations using `tests/Http3ControlStreamTest.php`; do not loosen `tools/c4-migration-behavior-audit.php`. Also remove two confirmed unused RoadRunner fixture variables. This is a small corrective follow-up, not another 100-file remediation batch. Re-run exact-head QA, analyzers, Source Audit and protocol tests.

**Batch 141 — propagated exception contracts:** Against Batch 140 SHA `b62ed1f2c52678afe8b0e600bd96b4fd2147f8cb`, the PHP 8.5 analyzer confirmed 124 blocking Psalm errors (72 additional `MissingThrowsDocblock` after exception propagation, 49 security taint, one unresolved include, two unused variables), down from 310 on the parent; PHPStan remains passing. The intervening audit-ledger correction SHA `21dfc99d8c5c8e475444b9d8a619da853e8680cc` removes the two unused fixture assignments. Batch 141 records all 72 newly exposed exception propagation findings in **37 actual affected PHP files**, retaining implementation behavior; rerun strict analyzers, Source Audit, QA, benchmarks and protocols on the new SHA before calling a reduction verified. The 49 taint and include findings remain a separate trust-boundary audit. No merge or tag.

**Shutdown-only SIGCHLD fallback regression follow-up:** The intermittent PHP 8.5 prefer-stable control-server watchdog (20 seconds) can occur if a shutdown child event is missed: existing stop logic starts per-worker kill timers but only the signal bridge reaps exited children. Add a 100 ms reaping fallback timer only after a supervisor is stopping with live children, cancel it in `run()` cleanup, and retain the immediate SIGCHLD path. `tests/SupervisorLifecycleTest.php` deliberately replaces SIGCHLD notification after WORKER_READY and asserts bounded teardown and zero lingering timers. Performance impact is restricted to shutdown, not steady-state request processing; full QA/worker/hosted runs required before marking the fix verified.

**Batch 141 proactive migration-audit repair:** Comparing source hashes against the pinned 2.1.2 baseline identified `ApplicationLifecycleHooks` and `AdmissionController` as the only two classes newly changed from the original published source by Batch 141 without existing ledger entries. Add documented/test-backed contracts (covered by `tests/ApplicationLifecycleTest.php`), retaining the Source Audit's fail-closed rule. This is a release-audit correction only; no runtime code or strictness changes. CI scheduling remains separately unverified.

## Historical dual-analyzer checkpoint — Batch 137 exact HEAD

- Exact source SHA: `ab1c344c66d55d0d2c80060faf4a34775b475124`; Security & Standards run `42557158756`.
- PHP 8.4 and PHP 8.5 Psalm: **390 blocking errors each**, with 1,069 / 1,070 non-blocking informational issues respectively. These version outputs overlap; do not add them.
- PHPStan: **1,000+ errors per PHP version**, truncated at the first 1,000 in table output. The visible inventories overlap in 80 PHP files; request full diagnostics with existing `PHPSTAN_TABLE_ERROR_FORMATTER_FORCE_SHOW_ALL_ERRORS=1` in a diagnostic run, without lowering strictness.
- Four PHPForge QA jobs, both benchmarks, both HTTP/3+QUIC jobs and all seven independent workflows pass. Only two analysis jobs fail on this exact source SHA.
- The PR has 70 unresolved review threads (69 not outdated); code-scanning threads can overlap active analyzer findings and must be verified against exact source, not separately double-counted.
- From Batch 138 onward: one coherent commit per batch, **up to 100 actual affected PHP files** (count unique files; do not pad), fixing Psalm **and** PHPStan together. Prioritize true security boundaries, tests and benchmark typing; preserve all strict CI gates and public behavior.

**Batch 138 submitted — concrete HTTP/3 fake typing and exact callback/OS boundaries:** Extract two in-memory QUIC fixture classes into their PSR-4 test namespace, give both factories real generically-typed stream contracts, preserve their original test behavior, and eliminate generic `object` property/method losses. Validate queued executor/pool cancellation callback values in two tests instead of unusable `unset` calls. Guard absent async QUIC result when producing failure diagnostics, remove a genuinely unused request array, require actual getrusage() results for C4 benchmark CPU samples and reuse the final usage snapshot for RSS while keeping all throughput/resource thresholds unchanged. **Eight actual affected PHP files plus this plan**; no unrelated filler. Exact-head Psalm, PHPStan, strict QA, native, Swoole, benchmarks and protocol validation PENDING. PR stays draft/open/unmerged; no release tag.

## Batch 139 — 100 affected PHP files, one commit (post-commit CI pending)

- Parent: `ebf68ad4915d1021e8d74cdf0bb51bc19e27db23` (Batch 138). Exactly **100 distinct PHP files** changed, plus this remediation tracker; file inventory below. One commit for the complete batch.
- Resolve concrete PHPStan nullable/mixed resource handling by validating all socket pair fixtures before destructuring; validate TLS subprocess streams, failed OS resource reads, HTTP/3/QUIC fixture contracts and ReflectionNamedType return values. Correct missing PHPDoc value/list/array shapes and non-null returns, fix genuine strict integer/float arithmetic in profiles while preserving numeric budgets. Preserve async callback ownership through mutable event/watcher holders instead of treating by-reference closures as constant null. Make no change to production `src/` files or public APIs.
- Eliminate Batch 138's six test-only fixture `UnusedProperty` findings by declaring the fixture state public where the fake transport owns it. Keep all original tests or replace tautological checks with real behavior assertions. No custom Psalm/PHPStan configuration, suppressions, baseline, dependency changes, benchmark threshold changes, merged PR or release tag.
- **100-file inventory:**
  - `benchmarks/adaptive_promotion_matrix.php`
  - `benchmarks/comparative_evidence.php`
  - `benchmarks/http1_sustained_bench.php`
  - `benchmarks/r0_host_profile.php`
  - `benchmarks/r2_scheduler_pressure.php`
  - `benchmarks/soak_runtime_telemetry.php`
  - `benchmarks/u08_hpack_source_profile.php`
  - `benchmarks/u08_qpack_source_profile.php`
  - `tests/AdaptivePromotionMatrixTest.php`
  - `tests/AdmissionChurnStressTest.php`
  - `tests/ApplicationLifecycleTest.php`
  - `tests/AsyncFileApiContractTest.php`
  - `tests/AsyncFileBackendContractTest.php`
  - `tests/AsyncFilesystemSocketFairnessTest.php`
  - `tests/BoundedConnectionPoolTest.php`
  - `tests/CachedDnsResolverTest.php`
  - `tests/ConnectionChurnTest.php`
  - `tests/ConnectionFactoryTest.php`
  - `tests/ConnectionPoolForkOwnershipTest.php`
  - `tests/ConnectionPoolReuseGuardTest.php`
  - `tests/ConnectionStateOwnershipTest.php`
  - `tests/ConnectionTest.php`
  - `tests/DatagramClientTest.php`
  - `tests/DialerContextIsolationTest.php`
  - `tests/DialerTest.php`
  - `tests/Fixtures/FakeHttp3ConnectionRaw.php`
  - `tests/Fixtures/FakeHttp3ConnectionStream.php`
  - `tests/Fixtures/FakeSwooleRequest.php`
  - `tests/HappyEyeballsDialerTest.php`
  - `tests/HostRuntimeTest.php`
  - `tests/Http1AbuseAcceptanceTest.php`
  - `tests/Http1AdaptiveConnectionSamplerTest.php`
  - `tests/Http1AdaptiveConnectionStrategyTest.php`
  - `tests/Http1ClientAdversarialPeerTest.php`
  - `tests/Http1ClientRequestWriterTest.php`
  - `tests/Http1ClientResponseParserTest.php`
  - `tests/Http1ClientWireTest.php`
  - `tests/Http1ConnectedExchangeTest.php`
  - `tests/Http1MalformedPeerTest.php`
  - `tests/Http1OutboundClientTest.php`
  - `tests/Http1PausedUploadTest.php`
  - `tests/Http1PoolReuseIntegrationTest.php`
  - `tests/Http1ProtocolFallbackTest.php`
  - `tests/Http1StreamingUploadTest.php`
  - `tests/Http1SustainedBenchTest.php`
  - `tests/Http2AbuseTest.php`
  - `tests/Http2ConnectedExchangeCancellationTest.php`
  - `tests/Http2ConnectedExchangeTest.php`
  - `tests/Http2ConnectionTest.php`
  - `tests/Http2FrameTest.php`
  - `tests/Http2MultiplexConnectionTest.php`
  - `tests/Http2OutboundClientTest.php`
  - `tests/Http3AbuseFaultAcceptanceTest.php`
  - `tests/Http3ClientControlTest.php`
  - `tests/Http3OptionsTest.php`
  - `tests/Http3RequestStreamTest.php`
  - `tests/Http3SessionTest.php`
  - `tests/HttpOutboundDispatcherTest.php`
  - `tests/IdleConnectionPoolStressTest.php`
  - `tests/IdleConnectionPoolTest.php`
  - `tests/MatchedReleaseDurationGateTest.php`
  - `tests/MetricsDiagnosticsTest.php`
  - `tests/NativeHttpConnectionTest.php`
  - `tests/OutboundAddressPolicyTest.php`
  - `tests/PhpQuicFacadeTest.php`
  - `tests/PhpQuicHttp3ConnectionTest.php`
  - `tests/PhpQuicHttp3WorkerTest.php`
  - `tests/PrivilegeDropOrderingTest.php`
  - `tests/ProcessExecutorForkOwnershipTest.php`
  - `tests/ProcessExecutorTest.php`
  - `tests/ProcessRunnerTest.php`
  - `tests/ProxyTcpInteropTest.php`
  - `tests/ProxyTunnelAdversarialTest.php`
  - `tests/ProxyTunnelCallbackOwnershipTest.php`
  - `tests/ProxyTunnelDrainPressureTest.php`
  - `tests/ProxyTunnelFaultAcceptanceTest.php`
  - `tests/PsrMiddlewareAdapterTest.php`
  - `tests/PublicApiMigrationConstructorCompatibilityTest.php`
  - `tests/QuicSessionForkOwnershipTest.php`
  - `tests/RegressionCompareTest.php`
  - `tests/ResponseTransferTlsTest.php`
  - `tests/RollingReloadPolicyTest.php`
  - `tests/SelectLoopTest.php`
  - `tests/SoakResourceProfileTest.php`
  - `tests/SoakRuntimeTelemetryTest.php`
  - `tests/Socks5IndependentTlsReferenceTest.php`
  - `tests/StreamRelayLargeTransferTest.php`
  - `tests/TlsHandshakeReadinessTest.php`
  - `tests/TlsListenerTest.php`
  - `tests/TraceContextTest.php`
  - `tests/VerifiedOriginalTargetTlsInteropTest.php`
  - `tests/WebSocketClientHandshakeTest.php`
  - `tests/WebSocketFrameParserTest.php`
  - `tests/WebSocketIntegrationTest.php`
  - `tests/WebSocketOutboundClientTest.php`
  - `tests/WebSocketOutboundTlsTest.php`
  - `tests/WorkerContextTest.php`
  - `tests/smoke/native_dialer_smoke.php`
  - `tests/smoke/native_filesystem_smoke.php`
  - `tests/smoke/native_mixed_peer_smoke.php`
- **CI verification PENDING:** record Psalm blocking errors for PHP 8.4 and 8.5, full PHPStan inventory (first 1,000 are truncated), four strict QA variants, benchmarks, QUIC, Swoole, hosted/native, source audit and documentation on exact resulting commit. Treat the recurring 20-second ControlServer watchdog failure as an independent QA issue; do not claim it permanently fixed. Do not merge or tag.

**Batch 139 exact-head analysis and QA regression correction:** Source SHA `e38f4458a7af64b6925d1a700c0f8f90e423fbc5`, Security & Standards run `42562667056`. Psalm blocking errors are **356 on each PHP 8.4 and 8.5** versus Batch 138 **392** (net **−36**; PHP versions are overlapping). PHPStan still displays **1,000+** truncated errors per version. Batch 139 introduced four confirmed Pest/QUIC test contract failures: the accepting QUIC facade fake's declared return incorrectly excluded the valid object, the HTTP/2 GOAWAY enum was incorrectly converted to an int, unsupported Pest `not->toThrow()` omitted the required exception argument, and successful executor sleep callback value 1 was incorrectly asserted null. Correct each in a small, dedicated **QA regression follow-up to Batch 139** (four PHP files plus tracker), without claiming it is another 100-file remediation batch; no production API or analyzer changes. Recheck exact new SHA on both analyzers and all QA/native/hosted/protocol/benchmark/doc lanes before declaring restored. The prior unresolved ControlServer 20-second watchdog is a separate issue, not considered fixed here.

## Historical dual-analyzer checkpoint — Batch 119 exact HEAD

- Exact source SHA: `93206cb0c2f91d25b0b2807777a667f375a8daec` (2026-11-09); latest completed **Security & Standards** run: 42541785714.
- PHP 8.4 and PHP 8.5: **789 blocking Psalm errors each**, with 1,126 / 1,127 additional informational issues respectively; counts are per analyzer job, **not additive**.
- PHPStan fails on both versions and reports **1,000+ errors** per job; only the first 1,000 are displayed. Use the existing `PHPSTAN_TABLE_ERROR_FORMATTER_FORCE_SHOW_ALL_ERRORS=1` environment setting in a diagnostic run to obtain a complete inventory; do not reduce analysis scope or strictness.
- All four PHPForge QA configurations, both benchmark jobs, HTTP/3/QUIC, Source Audit, portable native, hosted runtime, Swoole, HTTP/2 reference, release-candidate and documentation workflows passed for the exact SHA. The two PHPForge analysis jobs failed.
- **Execution target from Batch 120 onward:** up to 100 actual affected PHP files per commit, addressing Psalm and PHPStan together. Never pad a batch with unrelated files. Count unique files, and measure post-commit analyzer deltas before calling a batch verified. No custom analyzer config, suppressions, ignored paths or automatic merge/release.

**Batch 120 implementation submitted — benchmark evidence typing:** Four benchmark files plus this tracker. Normalize decoded regression-summary object keys into an explicitly string-keyed map, reject invalid keys before metrics comparisons, remove redundant float casts in adaptive aggregation, maintain named metadata checks and list return shapes, keep all original variance/latency/RSS budgets while making float expressions explicit, and use the guaranteed `proc_get_status()` shape (PHP 8.4+) without redundant array/cast checks. Replace a false-or-array `glob()` shortcut with an explicit failure distinction. No published Runwire API, benchmark thresholds, analyzer settings, test assertions, or security authorization changes. **Pending:** verify the exact Batch 120 commit in Psalm and PHPStan PHP 8.4/8.5, four strict QA variants, benchmarks, source audit, Swoole/native/hosted/protocol and documentation. Taint findings require separate boundary review.

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

**Batch 137 submitted — source-backed test contracts (up to 100-file cap):** Four PHP test files + remediation plan in one commit from B136 `1f37297b81c9238e08cc9302588209b53a711e00`. Validate `ReflectionNamedType` before calling `getName()` while preserving all public callback/write signature assertions. Check required release-workflow section delimiters before indexing split lists, preserving the exact workflow content tests. Verify both HTTP/2 asynchronous completion keys before retaining all original result assertions and cancellation behavior. Confirm the FrankenPHP worker request index is valid before selecting its fixture request, and explicitly consume declared callback parameters without changing responses. No test deletion or weakening, no benchmark/security/published API changes, no checker configuration modifications, no baseline/suppression. Post-commit QA, analyzers, protocols, native acceptance, benchmarks and Source Audit pending; do not merge or tag.

**Batch 135 exact-head CI verified:** `5a0c1182d50eefac73b22de57a084d9ac6090aa4`, Security & Standards run 42555889625. Both PHP 8.4 and 8.5 report **422 blocking Psalm findings each**; PHPStan remains **1,000+** truncated per version. All four PHPForge QA matrix configurations PASS, both benchmark jobs PASS, HTTP/3 PHP 8.4 PASS (PHP 8.5 pending at snapshot), and all seven independent workflows PASS. The two analyzer jobs still fail. Preserve the exact SHA and do not count PHP versions as distinct findings.

**Batch 136 submitted — asynchronous state and real benchmark provenance:** Three PHP files plus tracker, one commit. Carry native mixed-protocol smoke lifecycle progress through a single mutable event owner instead of falsely constant closure-captured scalars; explicitly reject absent bound HTTP/TCP/UDP socket addresses before passing to typed connections. Build the HTTP/2 outbound dispatcher through an explicit nullable mutable owner, validate that it has been constructed before the ready callback, and require both asynchronous completion keys before their existing equality assertions. Declare the actual benchmark CPU helper throw contract and use explicit floating-point arithmetic without changing throughput thresholds, sample units or execution timing. No suppression, detector configuration, benchmark or test-scope change; exact-head full QA and both analyzers **pending**. **Three actual affected PHP files**, under the 100-file cap; do not pad. Leave PR draft and unmerged.

**Batch 134 exact-head CI diagnosed:** `c9a38139ac92197b43601b564e3083075298dd59`, Security & Standards run 42555143327: PHP 8.4 / PHP 8.5 Psalm **420 blocking findings each** (441 → 420; 21 fewer unique), PHPStan **1000+** truncated. All four PHPForge strict QA variants failed **Pint only**, specifically `benchmarks/c4_mixed_file_network.php` (`ordered_class_elements` and `class_attributes_separation`) and `benchmarks/async_file_worker_profile.php` (`class_definition` and `braces_position`). Syntax, Reference Integrity, Duplicate Code, Pest, PHPCS, Deptrac and Rector passed. All independent workflows and benchmarks passed. Do not declare QA green.

**Batch 135 submitted — correct two Pint formatting regressions without functional changes:** Two benchmark PHP files + tracker in one commit. Alphabetize and separate typed anonymous-class properties under PHPForge PER policy; replace the short promoted-property anonymous constructor with a single initialized typed property set to the already-validated operations count before any callback is registered. This preserves mutable progress ownership and the original timing/output. No checker, test, threshold, public API or behavior changes; verify all four strict QA and both analyzers on the exact resulting source SHA. PR remains draft, unmerged, untagged.

**Batch 134 immediate syntax regression correction:** Parent `1568e5b30916ab20c19d6ac44eecbd002fda6d63`; B133 hosted PHP 8.5 strict QA detected a real syntax error in `benchmarks/c4_mixed_file_network.php`: a mechanical state-reference rewrite incorrectly inserted `$progress->` into the four anonymous-class *property declarations*. Restore valid `$networkConnected`, `$networkAccepted`, `$fileCompleted`, and `$sleepFinished` property names without changing their uses, callback mutability, benchmark timeouts or expected counts. B133's strict syntax, Reference Integrity, Duplicate Code, Pint checks failed for this one parse error. Correct this before new remediation work. **One PHP file + tracker in one commit.** Full exact-head hosted verification pending; no detector suppression, merge or release.

**Batch 132 exact SHA 129fab4b04e2a59d136e5df7a40280f2a6ef03bd:** Both PHP 8.4 and PHP 8.5 Psalm **441 blocking findings** each, down from 464 at B131 (23 fewer unique); PHPStan **1,000+** (display truncated). All four strict QA configurations, benchmarks, native QUIC, seven independent workflows pass. Only analysis remains blocking. Source: Security & Standards run 42553683360.

**Batch 133 submitted — real callback ownership and benchmark metrics:** Five PHP files plus this plan; one commit, under the 100 affected-file cap. Store asynchronous mixed-reactor completion state and filesystem pending count as typed mutable objects, so event-loop callbacks and completion checks share a single real owner. Retain original 48 network/file completions, read/write assertions and timeouts. Validate Linux CPU/RSS fields from getrusage before arithmetic rather than hiding untyped measurements behind casts. Remove provably redundant CLI and socket resource checks while preserving input failures; fix strict floating-point variance and reject negative QPACK sample indices before indexing. Dynamic source-loader taint findings remain under independent security review; no analyzer config, baseline, test, quality gate, limit or protocol change. Exact-head CI Psalm/PHPStan and QA verification pending; PR remains unmerged, draft, untagged.

**Batch 131 exact-head verified:** `4a44b91efa2c71ee2cc811c021cfb5f48a7d343e`, Security & Standards run 42553178666. PHP 8.4 & 8.5 Psalm **464 blocking errors each** (506→464; **net -42**). PHPStan remains **1,000+** truncated and both analysis jobs fail. All four PHPForge QA matrix configurations, both benchmarks, both HTTP/3/QUIC runs, Source Audit, Swoole/OpenSwoole, hosted/native, HTTP/2 reference, release candidate, and Sphinx documentation PASS. No merge, tag or release.

**Batch 132 submitted — benchmark resource and worker-test contracts:** Six PHP files (four benchmark profiles and two worker tests) plus tracker, one commit, up to 100-file cap. Treat CPU accounting as real required `getrusage()` key provenance instead of impossible array-type checks; preserve runtime-build environment fallback semantics on empty/false/'0' values; use explicit floats where strict Psalm arithmetic needs it, without changing benchmark budgets, runtime samples or protocols. Make an unused benchmark SHA-256 CPU workload value part of a real digest-length correctness assertion, detect empty latency/memory evidence before max/min, keep every original soak/time/resource cap. In the worker test fixture, validate the native worker handler owner class before reflection, reference instead of unsetting meaningful request/writer parameters, and reject failed socket reads before response parsing; the original lifecycle assertions remain. Remove redundant annotations from the HTTP/1 shared byte-budget fixture without deleting resource-ownership checks. Full hosted CI on exact new SHA **pending**. **No ignore rules, new dependencies, weaker assertions/thresholds, automatic merge or release.**

**Batch 130 CI evidence:** `eda2054fe9d3026c7c44cf49296d6088cb6c580f`: all 4 Swoole/OpenSwoole PHP 8.4/8.5 coroutine jobs now PASS after correcting two accidental `ArrayObject::append()` calls on a plain PHP array. All seven non-security workflows PASS; PHPForge strict QA and Psalm/PHPStan analysis still block at the current review point. B129 exact head `39c5e9eb569a9f5baf14688b6cb3b6873e414656` reported **506 Psalm errors per version** versus B128's 590 (**net -84**), though two were newly introduced array-method errors fixed in B130. B129 four QA configurations failed from that test error and two PHPForge Reference Integrity findings on an unresolved namespaced `RuntimeException`.

**Batch 131 submitted — remove duplicate type narrowings and restore full strict QA:** **10 PHP files + tracker, one commit.** Retain separate Pest non-null / instance-of assertions rather than appending redundant `?? throw` checks which Psalm rightly reports after inference. This avoids introducing non-existent `Infocyph\\Runwire\\Tests\\RuntimeException` references in QPACK codec tests, correcting both reference integrity issues and the 14 + 8 newly exposed static findings in native HTTP and coroutine test suites. Validate the two HTTP/2 callback result arrays explicitly before final length checks. Split pooled HTTP/1 cancellation type and reason checks so the type is established before property fetch. Keep production monotonic deadline helper's actual throws documented. Correct the HTTP/1 benchmark process-metadata split/key narrowing without altering metric values. Keep all original test assertions, runtime timeouts, sample counts and benchmark caps; no analyzer changes, suppression or merge. Exact SHA CI is mandatory before marking verified.

**Batch 130 immediate regression correction:** Parent `39c5e9eb569a9f5baf14688b6cb3b6873e414656`, all four PHP 8.4/8.5 Swoole/OpenSwoole focused acceptance jobs failed identically at `tests/BatchORuntimeIntegrationTest.php:235` with `Call to a member function append() on array`. During B129 the required `ArrayObject::append()` fixes in two earlier lifecycle fixtures also inadvertently changed two `$events[]` writes in an unrelated *plain array* coroutine sleep test. Restore exactly those two PHP array append statements, preserving the original sleep duration, event sequence assertions and all correct `ArrayObject::append()` changes in the other two fixtures. This is a real B129 regression; correction is **one PHP file plus tracker, one commit**. B129 full analyzer/QA results remain pending; verify B130 on exact head. No detector or test scope changes, merge, tag or release.

**Batch 128 exact-head CI verified (concurrent):** `4a4c1e7fab2fcab7557736675098afcb2ed43fb5`, Security & Standards run 42550791148. Psalm **590 blocking errors on each of PHP 8.4 and PHP 8.5** (614→590; **24 unique net improvements**, do not sum across PHP versions). PHPStan still displays **1,000+** per version, truncated. All four strict PHPForge QA configurations, both benchmarks, both HTTP/3/QUIC jobs and all seven independent workflows PASS. The TLS certificate fixtures now retain genuine, source-proven OpenSSL type guards without redundant Pest assertions; no merge/release/tag.

**Batch 129 submitted — 100-finding target / 100-file maximum, 23 actual PHP files + tracker:** This is a non-overlapping, forward-only carry of source-inspected Psalm/PHPStan remediation staged from pre-B128 Batch 127, rebased onto the exact B128 head. The only overlapping PHP file, `tests/PooledHttp1SessionTest.php`, was manually reconciled so its new B128 TLS validation and the incoming real listener-address check both remain. Correct typed optional callback results (HTTP/1, HTTP/2, HTTP/3/QPACK, QUIC, pooled, datagram, trace, coroutine and request lifecycle) without removing original expectations. Check false-returning socket/procfs readings and CLI arguments at real input boundaries. Document accurate benchmark phase shapes; bound real CPU/procfs data, selector array/list shapes, diagnostic queue lifecycle and worker-generation PIDs. Use `ArrayObject::append` rather than nullable offset assignment; retain original benchmark protocols, response schemas and limits. Extract internal monotonic time use from the Pest closure into a test-local deadline helper, preserving the identical supervisor 20-second watchdog. **Exact-head CI pending; the prior single ControlServer timeout is not declared permanently fixed.** No analyzer baselines, exclusions, custom checker settings, test deletion, weakened performance thresholds, public runtime API changes, or automatic merge/release.

**Engineering gates:** `CORE-01` contracts/security unchanged (source reviewed); `FLOW-02` genuine analyzer findings corrected with normal PHP code (CI pending); `TYPE` fail-closed return/callback/resource validation instead of type suppression (CI pending); `PERF-01` unchanged throughput budgets and metrics (benchmarks pending); `EXEC-03` all four PHPForge QA, analyzers, hosted/native/Swoole, HTTP2/HTTP3, Source Audit, release-candidate and Sphinx must pass on Batch 129 before completion. Actual issue reduction is not yet known.

**Batch 127 exact-head checkpoint:** `6a77c9a41e43b470c54f387122e19738827b2c36`, Security & Standards run 42549624114. Psalm **614 blocking issues on both PHP 8.4 and PHP 8.5** versus 610 on B126 (net **+4**, a regression requiring immediate correction); PHPStan remains **1000+**, output truncated. All seven non-security workflows, both benchmark jobs, both HTTP/3+QUIC checks and PHPForge QA PHP 8.4 stable/lowest and PHP 8.5 lowest pass. QA PHP 8.5 stable failed one existing `ControlServerTest` supervisor-termination watchdog (20s), 1189 other tests passed; analysis still fails. The premature Pest `expect($key/$csr/$cert)->not->toBeFalse()` assertions introduced in B127 caused Psalm to infer subsequent checked branches impossible, yet PHPStan inferred mixed/true at signing. Keep assertions and guards meaningful, and correct test-failure behavior instead of suppressing any detector.

**Batch 128 submitted — correct OpenSSL test validation after analyzer disagreement:** Eight affected TLS test PHP files plus tracker, one commit. Remove pre-guard Pest expectations that distort the analyzers' flow types; keep explicit fail-closed key/CSR/certificate guards before subsequent OpenSSL operations, and add an independent, meaningful `openssl_x509_parse` assertion on the signed certificate to strengthen fixture validity. Do not change supported algorithms, TLS endpoints, trust stores, signing options, test timeouts, security controls or analyzer configuration. Full exact-head CI including previously flaky control-supervisor test required before considering this validated. No merge, tag, release.

**Batch 126 exact-head analysis verified:** `be0785a91147945babed5895f52d6ffbcd1bdb0c` / Security & Standards run 42548890719. Psalm **610 blocking errors on both PHP 8.4 and 8.5** (624→610, **net -14**); PHPStan still displays **1000+** truncated. All four PHPForge QA configurations passed, including Reference Integrity and Pest; both benchmark jobs and seven non-security workflows passed. PHP 8.4 HTTP/3+QUIC passed and PHP 8.5 was still running its native QUIC soak at last check. Some taint edges surfaced in the expanded soak input call graph: SSRF, file, and sleep sinks remain live; they must be resolved at validated boundaries without bypasses. The newly exposed benchmark source exception contract and strict numeric operands also remain, recorded rather than hidden.

**Batch 127 prepared — fail-closed TLS identities and real protocol benchmark tests:** Ten existing TLS fixture PHP files plus new `tests/ProtocolCoreBenchTest.php` and tracker (11 PHP files, one commit). Move private-key and CSR validity assertions directly before signing, add explicit runtime checks when those operations fail, and preserve each original certificate assertion and TLS protocol behavior. This prevents false OpenSSL values from reaching `openssl_csr_sign` and exposes real failures rather than relying on downstream TypeErrors. Test actual HTTP/1/HTTP/2/HTTP/3 HPACK/QPACK/Huffman benchmark data paths instead of relying solely on PHPBench's reflection to exercise their private fixtures. No test gates weakened, no production behavior or analyzer settings changed; exact SHA validation and diagnostics pending. Keep PR open, draft and unmerged; do not tag or release.

**Batch 125 exact head analyzed:** `6e52e51627a3dcb54d33fa889d42068089afafed`, Security & Standards run 42548068769. Psalm **624 blocking findings each on PHP 8.4/8.5** (654→624, net -30); PHPStan still **1000+** truncated. Five QA configurations? Four strict QA variants all failed Reference Integrity due three `LogicException` references in namespaced `tests/ProcessExecutorTest.php`; PHP8.5 stable Pest also reproduced the existing `SupervisorLifecycleTest` deadline race (2 ready PIDs instead of 3). Benchmark and non-security CI passed, both HTTP/3 checks passed; analyzer jobs still fail. No suppression/threshold change made.

**Batch 126 submitted — resolve CI regression and validate soak evidence:** Seven affected PHP files plus tracker, one commit. Import standard LogicException in the namespaced test, remove a post-registration impossible timer-null branch; make supervisor lifecycle readiness/crash and graceful stop sequencing event-deferred rather than using unnecessary 10–40ms wall-time delays while retaining the exact 1.0s watchdog, three distinct ready PID requirement, and all restart assertions. Verify GC/OPcache snapshot structure, validate scalar JSONL metrics at input, assert timer sleep failures instead of discarding their return values, establish typed procfs resource counters, and reject fgetcsv null records; add malformed JSONL regression coverage and guard getmypid() false. Preserve all soak durations, RSS/FD/GC limits, benchmark schemas and security constraints. Measure exact-head Psalm/PHPStan and all strict QA; no merge/tag/release.

**Batch 124 verified:** `8d1d7660e583da919e1d74d9338b2faa40078af4`, run 42547214139. Psalm 8.4/8.5 **654 blocking errors each** (714→654, **60 net fewer**). PHPStan still reports **1,000+**, display-truncated. All four strict PHPForge QA configs, both benchmarks, source audit, hosted/native, Swoole, HTTP/2, HTTP/3+QUIC, release-candidate and Sphinx docs passed; analyzers remain red. No releases/merges/tags.

**Batch 125 submitted — typed cross-driver callbacks and remaining asynchronous test contracts:** Six test PHP files plus plan, one commit. Validate worker state-counter types instead of casting mixed; type the ArrayObject state and event handlers, preserve Swoole lifecycle semantics using explicitly typed event closures; use a shared mutable typed timer-handle owner for callback cancellation; guard nullable cancellation errors and deferred response owners without removing existing expectations; retain the HTTP/2 streaming body cancellation checks while checking actual subtype; validate unpacked frame codes; validate benchmark server PID. Fix three true exception-docblock omissions from B124. No detector suppression, weakened assertions, changed benchmark limits, production behavior edits, merge/tag/release. Exact-head CI and measured analyzer deltas pending.

**Batch 123 verified check-in:** `e661a229ba7e2c1c53e9a3b0c4c56f4ce7db2b78`, run 42545799931, Psalm **714 blocking errors on PHP 8.4 and PHP 8.5** (down from 715 at B122), PHPStan **1,000+** (truncated). Strict QA PHP 8.5 stable/lowest and PHP 8.4 lowest pass; PHP 8.4 stable only failed the 1-second PIDs assertion in the supervisor lifecycle test (two observed versus three), while 1,187 tests passed. This is a timing-sensitive test failure to reproduce and fix without reducing assertions or thresholds. Reference Integrity, both benchmarks, QUIC, source audit, hosted/native, Swoole, HTTP/2 reference, release candidate and docs pass. Re-run of the one QA job was rejected by GitHub while its workflow was still running.

**Batch 124 submitted — async test completion and mutable fixture contracts:** Five affected test PHP files plus tracker; one commit. Promote worker results into checked local values before dereference (fail closed if an asynchronous callback did not run); keep every original success/error/cancellation assertion. Use correctly generically typed ArrayObject fixtures and append APIs for integer-key event records, validate response header/byte and lifecycle marker types, and correct no-op callback signatures without relaxing test behavior. A corrected in-library test namespace permits the internal cancellation subscription check rather than removing it. Confirm actual proc_open success before protocol I/O and preserve the unsigned IPC failure assertions. **Target: around 100 combined Psalm and PHPStan findings; exact reduction must be measured, not assumed.** All strict QA/runtime/test/benchmark/analysis lanes require verification on this exact commit. No analyzer suppression, threshold change, test deletion, merge or release.

**Batch 122 exact SHA analyzed:** `a4f92e068bc9097cc9e1d4ca5bed3f19b08ee0aa` (Security & Standards run 42545313393): PHP 8.4/8.5 Psalm **715 blocking errors each**, down from 771 on Batch 120 (**net -56**, no double-counting across versions); PHPStan remains **1,000+** truncated on each version. Both PHP 8.5 strict QA jobs failed solely on PHPForge Reference Integrity: the concrete `Infocyph\\Runwire\\Tests\\CachedDnsFakeResolver` type introduced in B121 must reside in its matching filename, not `tests/CachedDnsResolverTest.php`. Pest, Pint, PHPCS, Deptrac, Rector and other completed quality checks passed; no release readiness claim.

**Batch 123 submitted — restore reference integrity after typed fixture extraction:** Move the existing test-only `CachedDnsFakeResolver` verbatim to `tests/CachedDnsFakeResolver.php` (the existing PSR-4 dev autoload map resolves it), leaving `cachedDnsFake()` and its tests unchanged. Document the actual PHPUnit `AssertionFailedError` propagated by `expectHttp3Fault()`. This targets the exact Batch 122 QA regression and one newly exposed Psalm exception-contract diagnostic without changing behavior, scan scope, suppressions, or published runtime code. **Three affected PHP files plus tracker**, one commit, exact-head CI pending; no merge/tag/release.

**Batch 122 submitted — benchmark numeric contract cleanup:** Parent B121 `cf1b36f625aefa1983b778467d50b6a0f57c69da` (exact-head CI still pending). In ten benchmark files, remove casts that Psalm identifies as redundant under accurate declared data types: aggregate sample sums remain in real floating-point divisions, HTTP/1 elapsed-time/CPU calculations retain original units and budgets, integer histogram keys remain integers, and adaptive-promotion path options retain their declared string type. Preserve all benchmark output formats, thresholds, samples, process behavior, and PHPForge settings. **10 PHP files + tracker; one coherent commit.** Post-commit Psalm/PHPStan PHP 8.4/8.5, four QA, benchmark, source, native/hosted/protocol and docs checks are required; no unverified claim of reduction. PR remains open, draft, unmerged, untagged.

**Batch 121 submitted — test fixture ownership and failure assertions:** Based on B120 exact SHA `9f3785c739be3867b1aa9a33e7a5858f6186532f` (PHP 8.4/8.5 Psalm **771** each; PHPStan still **1,000+** truncated; all four QA, benchmark and native/hosted/protocol/docs/source-audit lanes pass). Introduce the concrete test-local `CachedDnsFakeResolver` type instead of losing its properties and completion method through the production interface return annotation, type its queued callback, guard optional DNS result/pending closure, and assert the second waiter identity without removing existing test expectations. In eight protocol/cancellation suites replace **22 unsupported Pest magic `test()->fail()` calls** with native PHPUnit `Assert::fail()` (same deliberate failure contract). **9 PHP files + plan, one commit.** Exact-head CI analysis and full QA pending; do not merge, tag or release.

**Batch 119 submitted — Swoole acceptance resource validity and promotion fixture typing:** Parent `89bc3142223d596c892e4ed85ed3b0ee1c957f0e`; replace non-type-narrowing generic assertions around HTTP streams, TCP sockets, server reservations, client processes, JSON response bodies and lifecycle evidence with equivalent explicit `RuntimeException` failure boundaries in the Swoole acceptance fixture. Guard JSON list spread, the port separator and `glob()` fallback. In adaptive promotion, resolve configured Python paths and hostname with strict false/empty/zero handling equivalent to the prior `?:` rules, and validate the ephemeral port separator before extracting it. This targets `PossiblyFalseArgument`, `PossiblyFalseOperand` and `RiskyTruthyFalsyComparison` rather than changing runtime APIs or thresholds. No exclusions, suppressions, custom analyzers, or fixture assertion removals. Exact-head analysis/Pest/QA/hosted native/Swoole/HTTP3/Source Audit/benchmarks/docs pending; keep PR draft/open/unmerged, no tag or release.

**Batch 118 submitted — benchmark numeric input and worker-profile OS return contracts:** Parent `3f87208bcf4483bb1f615aa13c791580d68dad51` (Batch 117, PHP 8.4 Psalm **839**, down from B116's 907; PHPStan first 1,000 truncated), correct real benchmark boundaries in `regression_compare.php` and `async_file_worker_profile.php`. One finite numeric helper validates every metric before threshold calculations; the comparisons use typed floats rather than mixed offsets or unchecked casts, keeping 5% throughput, 2.5% CV, latency and RSS budgets unchanged. The filesystem benchmark removes a redundant PID type test, retains the self-capturing heartbeat callback without unused preinitialization, and explicitly rejects `getmypid() === false` before sampling. No production API, CI configuration, suppression, scan-scope, workload or test assertion changes. Exact-head PHP 8.4/8.5 Psalm/PHPStan, strict QA, benchmark, source, native/hosted/protocol, docs verification pending; do not merge/release/tag.

**Batch 116 exact analyzer checkpoint:** `8350c678d8aae29067d9d0134adb3dabf2afd8b2`: PHP 8.4 and PHP 8.5 Psalm **907 errors each**, unchanged in net from Batch 115 but seven `PossiblyFalseArgument` findings removed with other type issues newly exposed. PHPStan still reports >1,000 truncated first-page findings; all four QA variants, both benchmarks, docs, source audit, hosted/native and HTTP/2 PASS. HTTP/3 PHP 8.4 PASS; PHP 8.5 long adaptive matrix still pending at checkpoint. Two PHPForge analysis jobs FAIL. B117 focuses on previously confirmed invalid socket destructuring sites; exact-head verification remains required. No merge/tag/release.

**Batch 117 submitted — explicit socket-pair failure boundaries across protocol and fork tests:** Parent B116 `8350c678d8aae29067d9d0134adb3dabf2afd8b2`, validate 36 direct `stream_socket_pair()` results before destructuring across 19 test/acceptance files using file-owned resource-pair helpers; preserve pre-existing `expect()->toBeArray()->toHaveCount(2)` assertions in bounded pool fixtures and add guards to four existing indirect usages. Genuine PHP return `array<resource>|false` no longer flows to resource-consuming methods or array offsets unvalidated. No changes to production behavior, public APIs, benchmark measurements, analyzer setup, suppression, or CI scope. Initial Batch 116 PHP8.4 analysis reports **907 Psalm** (same numerical baseline as B115, with different issue composition), PHPStan first 1,000 truncated, four QA configurations settling; B117 exact-head CI must certify the net delta and all QA, native/hosted/protocol/docs/source/benchmark gates. One coherent batch commit; PR stays draft/open/unmerged, no tag/release.

**Batch 115 exact-head CI verified:** At `fd2f2be83e5ac883d84756dcb23f67083901153f`, PHP 8.4/8.5 Psalm reports **907 errors per job**, from B114 1,041 (**net -134**, test-only) and `InternalClass` 145 → 11. Remaining scope 211 benchmarks, 678 tests, 11 src, 7 tools. PHPStan remains FAIL with first 1,000 diagnostics truncated; both PHPForge analysis jobs FAIL. All four strict QA variants, native/hosted HTTP/3, H2 reference, Source Audit, benchmarks and docs PASS. PR stays draft, open and unmerged; no release/tag.

**Batch 116 submitted — validate real socket, JSON and process identity fixture boundaries:** Parent Batch 115 `fd2f2be83e5ac883d84756dcb23f67083901153f`, eight real test suites plus tracker. Five socket-centric suites now return checked two-resource pairs instead of unchecked `array|false`, preserving original Pest assertions and consolidating nine ConnectionScopeOwnership fixtures; HTTP/1 validates failed response reads. Two benchmark-report tests validate JSON object and workload arrays before access; the native worker lifecycle suite checks `getmypid()` once per actual fixture creation via a typed helper rather than passing `int|false` to constructors. No production API, benchmark workload, test expectation, analyzer configuration, suppression or CI scope changes. B115 exact-head QA has passed; B116 publication requires its own complete CI; verify both analyzers and all strict QA, native/hosted/protocol/docs/source/benchmark gates. No merge/tag/release.

**Batch 115 prepared — close residual Pest generated internal-class scope across QUIC, HTTP/2, HTTP/3, TLS and supervisor:** At parent `458c1d61bfec29333d25a37227e06e7e952dfe3c`, move 92 existing test cases into named `Infocyph\\Runwire\\Tests` helper functions in 12 affected suites (including 18 QUIC worker and 21 HTTP/2–HTTP/3 response cases). Keep all Pest registrations, datasets, assertions, fixtures and callbacks; do not alter production API visibility, analyzer configuration, test expectations or security controls. Targets the 145 remaining `InternalClass` findings verified in Batch 114's PHP8.5 Psalm **1,041** baseline; net reduction and any new diagnostics must be measured from a completed exact-head analyzer run. Batch 114 analysis PHP8.5 remains red on both Psalm and PHPStan; QA/protocol matrix settling before publication. One coherent commit, no merge/tag/release.

**Batch 114 submitted — second Pest internal ownership sweep:** Parent `b4be81b0a5c37e1d9d091e7189351cacef77cfbd` (Batch 113). Move implementation of 42 existing Pest cases into 42 named package-owned test helper functions in 12 more suites (WebSocket protocol, adaptive sampler/policy, adversarial corpus, TLS readiness, supervisor privilege acceptance). Keep registration names, assertions, inputs and execution contracts unchanged; no changes to production APIs, tests' pass/fail expectations, PHPForge settings, suppression or scanning scope. Batch 113 analyzer and full CI remained in progress at preparation; do not claim a net reduction yet. This batch also requires exact-head PHP 8.4/8.5 Psalm/PHPStan, four QA lanes, native/hosted/protocol/docs/benchmark/source audit certification, with any regressions fixed before release. One batch commit; no merge/tag/release.

**Batch 113 submitted — close Pest-generated `InternalClass` scope for additional package-owned test cases:** Starting from exact verified `4cb467f4d445aa3406cfbe78a79515ac1729f3ff` (PHP 8.4/8.5 Psalm **1,225**, 996 tests, 211 benchmarks, 11 src, 7 tools; PHPStan first 1,000 diagnostics truncated), preserve 78 Pest registrations/assertion bodies while moving implementation calls into 78 named `Infocyph\\Runwire\\Tests` functions across 16 test suites. This extends Batch 112's owned-callback technique to HTTP/2, HTTP/3, WebSocket, QUIC stream registration, adaptive strategy, supervisor privilege and time-test coverage. No production code, interface visibility, test assertions, data sets, custom analyzer configuration or suppression changes. 16 test files + tracker, one batch commit. Exact-head Psalm/PHPStan both PHP versions, all four strict QA lanes, benchmark/source/native/hosted/protocol/docs checks must certify a net reduction; until then batch is **submitted, not verified**. Leave draft PR open and unmerged; no tag/release.

**Batch 111 exact analyzer checkpoint:** At `e091f8ceb477ffad567d3643b05a55ca25509051`, PHP 8.4 Psalm reports **1,354** (from 1,351; **net +3**, not an improvement). Host benchmark unused `strlen` removed and adaptive summary cast removed, but removing the QPACK nullsafe fallback created five real Psalm nullability/throws findings; PHPStan also finds 13 mismatched array-key domain annotations in the new OPcache metrics helper. Four strict PHPForge QA lanes PASS; PHPStan remains >1,000 visible diagnostics and fails. Other source/hosted/native/doc checks PASS, HTTP3 8.5 was still finishing at the checkpoint. Batch112 must correct these regressions, not bury them. No merge/tag/release.

**Batch 112 submitted — cross-analyzer QPACK/OPcache contracts and Pest internal access pilot:** Keep QPACK's null-default coalescing on the property access without the redundant nullsafe operator, and document actual decoder exceptions; type OPcache metrics as arrays with arbitrary keys so checked string-key reads are not falsely advertised as a wholly string-keyed array. In **six package-owned Pest suites containing 30 test cases**, put internal API calls into named `Infocyph\\Runwire\\Tests` helper functions and leave a lightweight Pest callback calling each helper, preserving assertions, examples, fixture execution and test names. This targets the 426 `InternalClass` findings attributed to Pest's generated callback scope without exposing internal library APIs, custom Psalm hooks, baselines or suppressions. **Eight PHP files plus tracker**, exact-head Psalm/PHPStan, four QA lanes and complete CI pending. No merge/release/tag.

**Batch 110 exact analyzer checkpoint:** At `485f44ce62dead6aaef283a2e828cd99d7645097`, Psalm **1,351 errors on PHP 8.4 and 8.5** (1,366 → 1,351, **net -15**), by scope 213 benchmarks, 1,120 tests, 11 src, 7 tools. PHPStan still fails with a truncated first 1,000 diagnostics. Three strict QA lanes passed; PHP 8.4 prefer-stable QA failed solely on the pre-existing intermittent `ControlServerTest` 20-second shutdown alarm (1,187 other tests passed). The exact-SHA QA job was rerun; outcome pending and must not be assumed green. Source Audit, native/hosted/protocol/docs passed. No merge/tag/release.

**Batch 111 submitted — benchmarks: measured-input fidelity and PHPStan data shapes:** Correct OPcache footprint handling by requiring actual script-array records and non-negative integer OS metrics, rather than casting unknown/malformed evidence to zero; repair unused host-benchmark callback results with fixture checks, preserving configured write budget; keep ProtocolCore QPACK decoding on its known nonnullable result contract; turn HPACK/QPACK microprofile measurement closures into typed named functions to apply the `callable(int):int` contract to the executing measurement body (same warmup, trial counts and workload); and validate trial ID types and named phase offsets in adaptive matrix summaries without changing budget thresholds or success criteria. Seven benchmark PHP files plus tracker, no published runtime implementation changes or custom analyzers. Exact-head Psalm/PHPStan, four strict QA variants, Source Audit, benchmark and hosted/native/protocol/documentation tests **pending**; no merge/tag/release.

**Batch 109 exact analyzer checkpoint:** `e320fa4ecbda4827217e01434811195e2ce00b4e` reports Psalm **1,366 errors on PHP 8.4**, **net -100** from Batch 108 (1,466). `MissingClosureReturnType` and `MissingThrowsDocblock` are both **0**. Current Psalm by scope: 213 benchmarks, 1,135 tests, 11 src, 7 tools. PHPStan still fails (first 1,000+ diagnostics truncated). All other exact-head QA results must be reconciled before certifying B109 green. No merge/tag/release.

**Batch 110 submitted — validate test data and OS failure returns at the source:** Update 10 distinct benchmark/test-support-related test files: check decoded C4 ledger schema and JSON result before array use; explicitly decode checked HPACK hex fixture bytes; bound and verify `scandir` results in FD-leak stress tests; check Unix socket pair and reference response in H2 test support; validate closed TLS fixture streams, imported socket handles, real H1 peer/socket and streamed byte results, process fixture resources, and OpenSSL certificate/key/CSR fixture creation and listener port extraction. Retain existing real tests, assertions, workloads, production API, benchmark budgets, and CI analyzers. Exact-head Psalm/PHPStan, all four PHPForge QA, hosted/native/protocol/docs/source audit **pending verification**; keep PR draft/open/unmerged, no tag/release.

**Batch 108 exact-head analyzer checkpoint:** At `bda4ec177e8abc9d2cbe63042cedefac02d021bb`, Psalm reports **1,466 errors per PHP 8.4/8.5**, a verified **net -436** from Batch 107 (1,902). Remaining Psalm by scope: 213 benchmark, 1,235 tests, 11 src, 7 tools; `MissingThrowsDocblock` now 34 (from 470), with the remaining reports concentrated in eight test files. PHPStan still truncated at 1,000+ diagnostics. Four strict QA lanes and other checks were success/in progress as checked; keep full final-SHA verification pending. No merge/tag/release.

**Batch 109 submitted — explicit callback return contracts and exception propagation:** Based on Batch 107 exact-head diagnostics and Batch 108's residual exception reports, type **66 closure expressions** in 19 test/fixture files with the native PHP return type expected by actual operations, using `mixed` where PHP lacks a native resource type and `array` for generics; preserve callback values and behavior. Document **34 subsequently exposed missing-throws sites** across eight test helpers using declaring-method ownership. Total **27 distinct source test files** plus tracker; no runtime code, analyzer config, suppression or CI changes. All analyzer/QA/native/hosted/protocol/source-audit/docs checks **pending exact-head verification**. No merge, tag, release.

**Batch 107 exact CI verified:** `952d36f3a5b8ead13976becfeb60330510945eb2` (PHP 8.4/8.5) reports **1,902 Psalm errors each** (**net -110 from Batch 106**). `MissingOverrideAttribute` is now 0; all 4 PHPForge QA configurations and source/native/hosted/protocol/docs checks PASS; PHPStan still reports at least 1,000 visible errors and both analysis jobs FAIL. Remaining Psalm per scope: 244 benchmarks, 1,640 tests, 11 src, 7 tools. No merge or release.

**Batch 108 submitted — actual exception propagation declarations:** Target the **470 `MissingThrowsDocblock` reported call sites** across **64 unique PHP files** (benchmark methods, fixtures and test helpers). Map diagnostic sites to the enclosing named declaration, deduplicate actual exception classes, and document them using fully qualified `@throws`. Account for Batch 107 line-number movement from Override attributes when mapping error sites. Preserve function signatures, bodies, benchmark execution, inputs and API, with no analyzer exclusions/suppressions. The number of added declaration tags is not the net Psalm reduction; **exact-head PHP8.4/8.5 Psalm, PHPStan and 4 QA matrices plus Source Audit, native/hosted/protocol/docs pending verification**. Do not merge/tag/release.

**Batch 106 exact-head CI verified:** At `b783a80b5d83b39bf374ec761c60ee6c9d7410a2`, Psalm PHP 8.4 and 8.5 both **2,012** (baseline 2,473; **net -461**). By scope **244 benchmarks, 1,750 tests, 11 src, 7 tools**. All 4 PHPForge QA matrices PASS, native/hosted/HTTP3/H2/docs/Source Audit PASS. Analysis PHP8.4/PHP8.5 FAIL due to Psalm and PHPStan. PHPStan truncated at 1,000 visible errors (do not claim complete count). The `InternalMethod` issue family drops by 463 while `InternalClass` remains 426 due to Pest\PendingCalls\TestCall scope; test namespace migration alone is not a complete fix. No merge/tag.

**Batch 107 submitted — exact implementation override contracts:** From Batch 106 exact-head diagnostics, add PHP-native `#[\\Override]` on **110 verified interface/parent overrides in 29 test/fixture PHP files**, preserving each method body and signatures. One coherent commit, no production source or analyzer configuration changes. Tests and full CI **pending exact-head verification**. Continue next round on actual remaining issues, not guessed net reductions. No merge/tag/release.

**Batch 106 submitted — package-owned test namespace sweep:** At parent `f10436d4a7155e14f973dae9a8bbc3c6487c52b4`, migrate 69 previously global-namespace Pest suites to `Infocyph\\Runwire\\Tests`, retaining existing assertions and introducing explicit imports for unqualified PHP built-in classes used after namespace placement. Focus on internal implementation coverage (not public API exposure); no new analyzer configuration, exclusion, baseline, suppression, or source/runtime change. This fixes the package-ownership root cause behind `InternalClass`/`InternalMethod` diagnostics and preserves test helper declarations in their new namespace. The B105 exact-head baseline was Psalm **2,473 per PHP version**, PHPStan **at least 1,000 visible errors** (output truncated), all four strict QA variants PASS, 30/36 checks PASS with 4 failing analysis checks and 2 skipped. **B106 exact-head Psalm/PHPStan, Pest, four QA variants, source audit, hosted/native/protocol and documentation verification pending**; do not merge/tag/release. Target next batches toward complete benchmark/test analyzer remediation, not merely namespace cleanup.

**Batch 104 exact CI results:** At `aa19b8c6ac21751d74891dec054f5f70c92bb2d4`, Psalm reports **2,473 errors per PHP 8.4/8.5**: **244 benchmarks, 2,211 tests, 11 src, 7 tools**, down from the prior 2,490 / 261 benchmark findings. PHPStan still fails with >1,000 first-page diagnostics, but benchmark `adaptive_matrix_compare.php` drops from 62 observed PHPStan findings to **1** after enforcing input shapes. Four QA variants fail exclusively on Pint's `no_superfluous_phpdoc_tags` in its redundant `@param mixed` helper declaration. Batch 105 moves that helper into the shared summary module and removes the tag. Benchmark verification and all static analysis remain blocking until exact-head CI confirms. No merge/tag/release.

**Batch 105 prepared — validate benchmark summary evidence once:** Refactor `adaptive_matrix_summary.php` and `adaptive_matrix_compare.php` to share a single runtime-validated phase-envelope helper, preserving output values, thresholds and measured workloads. Reject malformed input shapes before offset use; compute numeric summaries from checked values; fail on undersized variance inputs before division. This reduces duplicated validation, addresses PHPStan mixed-array concerns, and does not change custom Psalm configuration or published Runwire API. Exact-head CI pending; keep benchmark-first priority, no merge/tag/release.

**Batch 104 — validate benchmark phase evidence before numerical comparisons:** At `f348e73d3b1c454fe2127584854a6addb51db890`, introduce explicit shape checks for adaptive benchmark comparison input and phase metrics; use the existing finite nonnegative metric validator for typed numeric access. This preserves comparison thresholds, source/runtime APIs, output keys and gates. Fix the malformed per-parameter PHPDoc. This targets genuine PHPStan mixed-offset/cast and Psalm runtime-validation findings, not custom Psalm behavior; exact-head tests and analyzer results pending. B103 and B102 CI status still subject to independent verification. Benchmark-first; no merge/tag/release.

**Batch 103 submitted — benchmark sample fidelity:** After Batch 102, fix percentile and CPU sample calculations in U01/U03 so strict arithmetic uses explicit float operands without changing measurement units; guard the R2 readable-wait socket resource inside the closure that actually consumes it. Do not change the benchmark workload, number of trials, public APIs or Psalm configuration. CI for B102 was still active when this follow-on batch was prepared. B103 count and QA **pending**; retain benchmark-first priority and all security findings.

**Batch 102 submitted — benchmark contract integrity:** Continue benchmarks first at parent `3d9abde31d52a12445ebb1189e4e371c10cf6e39`. Repair benchmark writer interface overrides and typed callable lifecycle, native PID false-handling, request resetter override, and accurate benchmark exception docs. These are small real implementation-contract fixes, not analyzer config changes or fabricated tests. The latest B101 analyzer/QA run is still in progress; no net error reduction claimed until exact-head CI. Benchmark findings remain priority ahead of tests. No merge/tag/release.

**Batch 100 exact CI reconciliation:** At `ad14d499ace79f5c636945b7285cb9e643e610d6`, Psalm **2,504 errors** on each PHP 8.4/8.5: **275 benchmarks, 2,211 tests, 11 src, 7 tools**. Benchmark Psalm reduced from original 719 to 275. PHPStan **FAIL**, output limited to first **1,000+ findings** under PHPForge's newly expanded tests+benchmarks scan (at least 553 benchmark and 447 test PHPStan diagnostics in first 1,000). This failure also existed at Batch 98; earlier lines claiming PHPStan PASS for those batches were incorrect and are superseded by this explicit result. Four PHPForge QA variants **FAIL only on Pint** in `benchmarks/u10_pool_live_profile.php` (`no_unused_imports`, `blank_line_before_statement`); Pest, reference integrity, PHPCS, Rector, Deptrac and other reported QA components pass. B101 corrects the exact U10 formatting along with numeric measurement contracts, but full quality gates remain blocking until new CI is verified.

**Batch 101 implementation submitted — preserve benchmark measurement arithmetic:** Eight further benchmarking scripts get explicit numeric type boundaries in percentiles, CPU accounting, drift limits and throughput calculations without changing input budgets or output semantics. Prepared against B100 `ad14d499ace79f5c636945b7285cb9e643e610d6`; do not mark verified before B100 QA and subsequent B101 exact-head CI. No project-local Psalm changes, no security suppressions, no merge/release.

**Batch 100 started — benchmark measurement numeric type fidelity:** Replace implicit mixed int/float arithmetic only in eight standalone benchmark measurement/summary utilities with explicit numeric conversions. Keep percentile cutoffs, clock domains, response payloads, sample counts, measured workloads and rounding unchanged. This targets the previous benchmark `InvalidOperand` findings, without changing security analysis or adding suppressions. Batch 99 exact-head QA and Psalm are still in progress at the time of this implementation; do not mark either batch complete until CI evidence is available.

**Batch 99 started — benchmark-only typing:** Batch 98 exact `e73dab26c70f595af8823512a69979f486991e2d` has Psalm **2,571 per PHP version**, benchmark findings **342** (down from 468), PHPStan PASS, all four QA PASS including repaired ProtocolCore reference. The U10 TCP pooling benchmark now validates nullable isolation state, actual resource samples, pipe read errors and numeric measurement types, and documents propagated errors. Remaining benchmark concerns continue to be open; B99 post-commit exact CI is pending. Do not merge, release or tag.

**Batch 98 started — benchmark exception contracts and QA compatibility:** Batch 97 `734ab5722bb2d71954fbe437042410530eaf7552` reduced Psalm **2,946 → 2,700** per PHP 8.4 (benchmark findings **719 → 468**), but the namespace change to the existing globally referenced `ProtocolCoreBench` broke five `ProtocolProfilingBenchTest` tests and PHPForge Reference Integrity. Restore only that class's global namespace, preserving its new exception docs and safe initializations. In 12 standalone benchmark scripts, add exact `@throws` contracts to the existing function docs based on observed Psalm diagnostics; preserve all behavior. No custom Psalm config, suppressions, weakened analyzers, merge or release. Exact post-B98 results pending.

**Batch 97 started — benchmark-only remediation:** At Batch 96 exact `043ed615dba6ca12b20eba52cb484c8570fe8d35`, PHPStan and four QA matrix jobs pass; Psalm **2,946 per PHP version** (Batch 95 was 3,069), of which **719** remain in `benchmarks/` (34 files). Prioritize benchmark correctness before the remaining tests. Five PhpBench classes account for **266 benchmark findings** (missing initialization, propagated exception contracts, internal ownership, and benchmark reachability). The batch scopes them to the Runwire namespace (not changing internal visibility), adds safe upfront fixture initialization while retaining PhpBench per-iteration setup, and accurately documents exceptions for benchmark entry points. Actual net reductions and full tests **pending CI**, no custom Psalm settings or suppressions, no merge/release.

**Batch 96 started — internal test ownership continuation:** Eight more self-contained Pest test suites move under `Infocyph\\Runwire\\Tests`, with explicit native PHP class imports. Batch 95 was verified at **3,069 Psalm errors** on both PHP 8.4/8.5 (net -209 from Batch 94, net -717 from the updated PHPForge baseline 3,786). PHPStan PASS, all four strict PHPForge QA variants PASS (PHP 8.4 prefer-lowest passed on exact-SHA retry after an intermittent 20-second ControlServer shutdown alarm); Source Audit, docs, hosted/native and protocol workflows PASS. Batch 96 exact new-head analysis **pending**. Remaining test/benchmark issues, source taints and executor file/SSRF taints are not waived.

**Batch 95 started — package-owned test internals:** Based on Batch 94's confirmed net reduction of 508 Psalm InternalMethod findings, nine additional pure Pest suites (without global helper declarations) are moved under `Infocyph\\Runwire\\Tests` and given explicit global exception imports where needed. This is a source-unchanged test ownership adjustment, not a Psalm suppression, public API change, or benchmark exclusion. Batch 95 exact-SHA Psalm/PHPStan, QA, native/hosted, and source-audit verification **complete**; Psalm **3,069 per PHP version**, net -209, PHPStan PASS. Initial PHP 8.4 prefer-lowest QA failed on intermittent `ControlServerTest` alarm; job rerun PASS on same SHA.

**Updated PHPForge 5c08396 / Batch 94 baseline:** Re-ran failed `Security & Standards` analyzer jobs for `35740aa0da79f959c50e12fd6bc3570f141ceddd` using `dev-main@dev` as already configured. PHP 8.4 and 8.5 both report **3,786 Psalm findings**: **3,049 in tests**, **719 in benchmarks**, **11 in src**, **7 in tools**. The previous 1,588 dead-code reports fall to **4 UnusedClass and 17 UnusedProperty across the entire analysis scope**; the test-visible reachability now works, but broad source/test/benchmark analysis exposes internal API ownership errors, exception contracts and test typing. PHPStan remains green; CI analysis is still red. No suppressions, exclusions, custom Psalm plugin or per-project Psalm config are introduced. First test-ownership remediation (Batch 94) updates eight pure Pest test suites to the package test namespace with real PHP built-in imports. Batch 94 exact SHA `e3ac1a507736e16d1aa390c37b5764d2da2e8cdb` is now **verified at 3,278 Psalm errors** on both PHP 8.4/8.5, a **net decrease of 508** (all in tests, InternalMethod 1,335 -> 827; InternalClass remains 436). PHPStan passes and four strict QA variants pass. Source Audit, docs, hosted/native and release-candidate workflows passed. No claim of zero: security findings and test/benchmark typing remain open.

**Batch 93 exact analyzer results — custom Psalm integration removed:** Delete the project-local `psalm.xml` override and `tools/psalm/AuthorizedWorkerPathTaintModel.php` plugin, and let the unmodified PHPForge `resources/psalm.xml` drive all subsequent analysis. This deliberately restores PHPForge's `findUnusedCode=true`, whose reports must be triaged as external-library reachability rather than blindly deleting APIs. At baseline `b7c42eb1b3d774dc0b310c197b83e71c0e96caba`, Psalm reports 10 errors per PHP 8.4/8.5 (8 internal plugin API violations and 2 worker `TaintedUrlComponent`); PHPStan 0 and all four QA variants green. At `092454b095cf9a10357bafd919ef286c07454e28`, PHP 8.4 and PHP 8.5 each report **1,595 Psalm findings**: **820 UnusedProperty, 646 UnusedMethod, 122 UnusedClass, 2 TaintedSSRF, 5 TaintedFile**; **0 internal-plugin errors** and **PHPStan PASS**. This restores PHPForge's whole-project dead-code reachability heuristic, which does not recognize external consumers of Runwire's distributable library APIs. Do not delete library behavior to satisfy false reachability reports. The seven authentic worker file/SSRF taint flows remain blocking. Exact SHA broader CI is still in progress; do not claim green. PR stays draft, open and unmerged.

**Latest verified code revision:** `0f0bc265c3ab4083b894259b198c92370988aa11` (Batch 88, including successful rerun of PHP 8.4 prefer-stable QA). Exact HEAD: **PHPStan 0** on PHP 8.4/8.5; **Psalm 7** blocking, all worker IPC file/SSRF taints (2 SSRF, 5 file), **0** other Psalm errors. All **four PHPForge strict QA variants PASS** (PHP8.4/8.5 lowest and stable), scanner zero prohibited directives, Pint/Pest/PHPCS/Rector/Deptrac green. Source Audit, native/hosted/protocol/docs/release-candidate checks PASS; 36 reported checks: **31 success, 3 failures (Psalm plus two PHPForge Psalm analysis jobs), 2 conditional skipped**. **0 unresolved PR review threads**. PR remains draft and unmerged, not release-ready.

**Open P0 trust-boundary issues:** CLI audit tools accept process argv-selected local paths that Psalm marks tainted even after `realpath`, `is_file`, size checks and CLI-only guards; the private executor receives IPC-selected filesystem paths, with an optional unrestricted filesystem mode, so its data really does originate outside the worker. Psalm's taint sanitizer metadata (`@psalm-taint-escape` and `@psalm-assert-untainted`) is explicitly rejected by PHPForge's skip-directive scanner. Do **not** silence taint analysis, introduce hidden annotations, reroute dangerous operations solely outside scanner coverage, weaken the scan configuration, or declare these 14 errors resolved without a sound authorization and sanitizer model. Six unresolved PR review threads still address these tainted CLI sinks. A maintainer-approved design decision is required if the current arbitrary-path CLI/IPC contracts are to change or if strict tool policy is to distinguish verified sanitizers from suppressions.


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
| 35–77 | Close analyzer/type contracts, PHPStan and strict PHPForge QA | 14 Psalm taints remain | PHPStan PASS on PHP 8.4/8.5; all four strict QA lanes PASS at `450109cb`; 14 real input-to-path taints not resolved |

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

### Batch 46 first exact-head QA review

- SHA `7181043e9aedadc05176b2a3e7a3f8fc487db40f` on PHP 8.4: **196 Psalm errors**, including **90 MissingThrowsDocblock** and the unchanged **17 P0 taint findings**; this is a *three-error regression* from the prior 193. Most newly annotated exception contracts simply propagated to unannotated callers. Do not mark the source-locations closed by counting PHPDoc additions.
- PHPStan identifies one actual child-status refinement issue: a validated `int|null` PCNTL status is forwarded through the original mixed local rather than its narrowed `int` value. Forward the validated local without changing callback semantics.
- Source Audit fails because removing unsupported exception annotations restores `RuntimeCapabilities` and `RuntimeOptions` to their exact published 2.1.2 hashes. Their two prior migration entries are therefore stale and must be removed; confirmed by Git tree blob comparison, with **209 changed published source types and 209 remaining ledger dispositions**.
- PHPForge QA and remaining protocol lanes must be checked on the exact follow-up correction SHA. Preserve both code scanning engines, taint checks, source-audit strictness and migration signoff requirements.

### Batch 46 post-correction QA and review feedback

- Exact-head `09f989e1fdc2cc0780c3fe0ea10ab10b409ed1bd`: PHPStan is **green on PHP 8.4 and 8.5**; Psalm remains **196 errors on each**, consisting of 90 MissingThrowsDocblock, 17 security taints and 89 other errors. The source audit now passes with 209 exact source dispositions after pruning stale ledger entries.
- Both QA matrices report Pint-only formatting issues: an overindented PHPDoc opener in `StreamRelay::close`, and `SwooleDriver::workerStartCallback` being out of the repository's ordered private-method layout. Restore the intended comment alignment and move the callback helper into the proper method order, keeping the original configure PHPDoc and all runtime behavior unchanged.
- Four stale OpenSSL annotation review threads and four other resolved historical analyzer threads were closed only after their specific issue types disappeared from exact-head diagnostics. Active taint, resource guard and callback-race review threads remain open.
- The next work is not another indiscriminate `@throws` sweep. Resolve actual security trust boundaries, analyze propagated callback `Throwable` contracts without degrading lifecycle behavior, and retain defensive runtime guards even when Psalm's closed-world inference misclassifies them.

### Batch 47 — 50-file analyzer contract and resource-lifecycle remediation

- **Exact starting SHA:** `bbdbceff38f2f413932eac91fbbd88811c1db2c7`. The completed PHP 8.4 and 8.5 analysis lanes both report **190 Psalm errors**: 84 MissingThrowsDocblock, 47 TypeDoesNotContainType, 14 RedundantCondition, 11 RedundantConditionGivenDocblockType, 17 security taints (9 file, 6 SSRF, 2 header), and 17 other diagnostics. PHPStan and all non-Psalm CI checks passed.
- This round contains **50 distinct files in one commit**: **44 source files** with directly reported issues, **4 focused regression test files**, **one migration ledger**, and this tracker. No unrelated filler is included.
- Reconcile original PHPDoc and duplicate exception PHPDoc blocks separated by `#[\\Override]` before adding any new propagation tags. These split blocks left some earlier `@throws` attempts ineffective, especially the response-writer and hosted-driver implementations. Preserve existing implementations and callback boundaries.
- For actual optional/closed PHP resources, use the analyzer-supported `resource|closed-resource` contract rather than removing `is_resource()` guards. Adjust process pipe close ordering to remove the array element before `fclose`, and use non-list by-reference I/O set typing because `stream_select` may rewrite indexes.
- Keep required event-loop reentrancy, native QUIC capability, closed TLS stream and cancellation guards. Where the analyzers provably disagree about default-argument constructors, extension constructors, coroutine resume side effects or callback-mutated state, use **local, issue-specific** Psalm annotations with adjacent justification; do not disable analyzers or relax P0 taint analysis.
- Test closed process stdin input and runner reuse, writable watcher closure, host-coroutine stop during suspension and duplicate W3C tracestate rejection. Restore two already reviewed published-source ledger entries (RuntimeOptions and RuntimeCapabilities) because their source annotations are changed again; keep the pinned 2.1.2 comparison and maintainer-signoff requirements.
- **Gates are pending on this new exact SHA**: PHP 8.4/8.5 Psalm and PHPStan; four PHPForge QA dependency lanes including Pint/PHPCS/Pest; Source Audit; HTTP/3+QUIC; hosted Swoole/OpenSwoole; packaging; docs and native runtime. Count only *net* diagnostic changes, address any new caller-site and formatter regressions before closing.
- **P0 unresolved:** all 17 file/SSRF/header taint findings require dedicated, test-backed trust-boundary work. No global suppressions, weakened security policy, merge, tag or release.

#### Applicable PHPForge engineering checkpoints

- CORE-01 correctness/security and contract priority: existing protocol, cancellation, worker ownership and stream lifecycle preserved; runtime checks retained.
- FLOW-01 quality detectors: both static analyzers, formatting, security scans, tests and CI remain required; evidence pending new SHA.
- FLOW-02 root-cause fix discipline: treat duplicate split PHPDocs and closed-resource doc models as underlying causes; do not claim remediation from annotation count alone.
- EXEC-03 completion evidence: final gate status is **pending** until every required check runs on the final commit.

### Batch 47 exact-head QA correction

- First PHP 8.5 analysis: **123 Psalm errors**, a net reduction of **67** from 190 (41 MissingThrowsDocblock, 41 TypeDoesNotContainType, 7 RedundantCondition, 17 P0 taints and 17 other). PHPStan reports three list-versus-array mismatches in ProcessRunner after stream_select() rewrites keys.
- Four QA lanes found one new Pest test assuming closed stdin is accepted; Command::stdin correctly rejects invalid closed resources. Correct the regression test to assert the existing rejection contract and verify runner reuse.
- Pint flagged four PHPDoc alignment issues in FiberScheduler, PhpQuicListener, Connection and TcpListener. Move freeform explanations ahead of annotations and normalize indentation. Fix the affected ProcessRunner array parameter contracts to accept keyed arrays.
- Corrective commit validation required on exact SHA, including PHPStan, Psalm, Pest, Pint, Source Audit, Swoole, QUIC and other CI gates. P0 security taints remain open, no merge/tag/release.

### Batch 48 — approximately 100 targeted error sites: reentrancy, throws and resource ownership

- **Exact pre-batch SHA:** `6cf61232edf4d19f15331c62500c815c76773a44`. PHPForge PHP 8.4 and 8.5 both report **120 Psalm errors**, comprising **41 MissingThrowsDocblock**, **41 TypeDoesNotContainType**, **7 RedundantCondition**, **17 security taints** and **14 further contract/type diagnostics**. PHPStan passed; all four PHPForge QA lanes, portable native 8.4 and Psalm scan were red, requiring exact-head follow-up.
- **Target, not a claimed result:** address the 41 logged exception sites and roughly 50+ additional state/resource/nullability/type-guard sites. This is a ~100-finding work batch; **only the new exact-head Psalm logs determine actual net fixes**. Do not pad commits to an arbitrary file count.
- Control plane, HTTP/1/2/3, WebSocket and response-scheduler sites now document actual throwable contracts at their declaring methods. This can propagate new exceptions to caller sites; re-run both analyzers and handle those callers without hiding failures.
- Preserve callback-driven state transitions by reading settled/closed/pending flags *after* callback execution; introduce small, explicitly named lifecycle readers where Psalm incorrectly holds a stale boolean/literal across user-defined hooks. Keep dispatch, timeout, reentrancy, EOF and cancellation behavior unchanged.
- Rewrite nullable token fast-fail as `$token !== null && $token->isCancelled()`, preserving active token deadlines/subscriptions on the false branch rather than narrowing it to `null`. Maintain timeout ceilings and observer release.
- Document closed-process handle entries accurately, revalidate live SelectLoop stream resources after pruning, and reject a TLS-upgrade stream that closed before/during the asynchronous handshake. Add a closed-stream completion regression test with an exact-once notification.
- Replace optional native-extension class resolution with a typed, fail-closed `class_exists` helper and eliminate impossible `is_object(new $class(...))` branches without removing required method/ALPN verification. Preserve QUIC worker isolation and Swoole host capabilities.
- **Source Audit:** checked each affected published 2.1.2 class against the pinned migration ledger; all previously published touched source classes already have a corresponding test-backed disposition. Non-published 3.0 types do not require 2.1.2 dispositions. Do not weaken the Source Audit.
- **P0 outstanding until separately verified:** 9 TaintedFile, 6 TaintedSSRF and 2 TaintedHeader, involving the intentionally general-purpose Dialer, isolated worker path commands, CLI source-audit tools, and native PHP response output. No taint suppression, broad taint escape or analyzer configuration relaxation.
- **PHPForge checkpoints:** CORE-01 preserves protocol/security contracts; FLOW-02 validates actual root-causes, not annotation counts; TYPE and SEC require live resource/cancellation and trusted I/O checks; OPS requires worker lifecycle safety; EXEC-03 and FLOW-01 remain **pending** until exact new SHA Psalm (8.4 and 8.5), PHPStan, four QA lanes, Source Audit, native/hosted HTTP protocols and docs all complete. PR remains draft and unmerged; no tag/release.

### Batch 48 exact-head QA correction — native types, PHPDoc ownership and complexity

- On `ae8161941d0fb5cc59601016f1bd69a9abb9e28f`, **Psalm PHP 8.5 = 47 errors**, down **73** from the prior 120, with 17 P0 taints still present. This is NOT release-ready: PHPStan reports 19 problems and PHPForge QA flags the skip-directive scanner and at least one Pint method-order issue.
- Six PHPStan unused-throws errors came from attaching existing method PHPDocs to newly inserted state-reader helpers. Move each entire docblock back to its original throwing method; retain helper accessors without speculative throws.
- Restore precise optional QUIC constructor types without suppressions: instantiate the validated dynamic extension classes using ReflectionClass::newInstanceArgs, which yields an object type and still propagates constructor errors. Remove an obsolete QUIC method-specific Psalm suppression. Keep the existing required method checks, TLS verification and finalizers.
- Bound external-stream ownership after asynchronous TLS handshake via a separately typed live-resource check. Do not remove the closed-handle recheck: the peer may close while an external callback runs.
- Reduce hot-loop SelectLoop duplication by a single small typed live-watcher collector while preserving per-resource guard, avoid extra per-tick intermediary arrays, and use the null-safe token predicate for HTTP/3 outbound without raising class complexity.
- Align WebSocketClientUpgrade private method ordering for Pint. **Verification pending on follow-up exact SHA**: both PHPForge analysis engines, both PHP versions, four QA matrices, security scan, Source Audit, Swoole/QUIC/native acceptance and packaging.
- Continue to treat all 17 security-taint reports as active until actual trust-boundary tests exist. The other 16+ locally annotated suppressions remain blocking in PHPForge's skip-directive checker; cannot claim green by removing the scanner.

### Batch 49 — exact-head 41-error reconciliation and native/exception convergence

- Baseline `603de6635b3e9f54aff64508f2c554b6aa0f551a`: **41 Psalm errors** each on PHP 8.4/8.5 (12 MissingThrowsDocblock, 17 security taints, 12 other), **five PHPStan** failures, four PHPForge QA failures and unresolved skip-directive policy findings. Source Audit, native hosted integration and most other CI jobs green. The 190/196-error counts are obsolete.
- Correct nullable cancellation-token access during HTTP/3 startup; preserve cancellation state transitions.
- Keep runtime guards for dynamic optional QUIC extension classes while supplying class-string contracts to ReflectionClass. Follow the repository Rector style for new method calls; do not bypass ALPN or verification.
- Preserve Swoole watcher cancellation after an external owner closes a watched stream; remove stale local registrations without passing closed resources to the reactor.
- Reduce SelectLoop cognitive complexity without removing deadline checks or changing timer semantics. Restore Pint's static/private method grouping in the HTTP/3 client, SelectLoop, and TLS upgrade helper.
- Add accurately thrown exceptions at already-reported method boundaries across HTTP/1/2/3, coroutine ownership and stream relay; observe how these propagate in the next analyzer run rather than treating tags as proven net reductions.
- **Security-taint reports remain open, unsuppressed**: network dialer trust policy, host response headers, audit CLI file handling and executor subprocess operations require explicit sink/source validation and test-backed treatment.
- Re-run exact SHA PHPStan, Psalm, all four PHPForge QA lanes, skip-directive scanner, Source Audit, QUIC/host suites and docs before closing batch. No merge, tag or release.

### Batch 49 exact-head QA audit

- On `dc5c23b8a1c25165084f37d4054b7c0eca691753`, Psalm PHP 8.4/8.5 both returned **35 errors**: 17 security taints, 5 missing exception contracts, 3 unnecessary var annotations, 4 reentrant HTTP/1 condition warnings, 3 type-does-not-contain findings, 2 resource/docblock warnings and 1 internal API designation. Net **-6** from the 41-error baseline.
- Both PHPStan lanes reported **four errors**: HTTP/3 client cognitive complexity 81/80, and three mismatched `class-string` annotations on native QUIC extension reflection sites. Runtime/host and Source Audit checks that completed passed.
- Four PHPForge QA lanes still rejected 16 previously introduced `@psalm-suppress` directives. Do not mark QA green while those remain; preserve legitimate suspension/reentrancy/resource checks and remediate without scanner bypass.

### Batch 50 — QUIC typing, callback reentrancy and validated header output

- Use static, non-loading `Quic\\Connection::class` / `Quic\\Listener::class` strings under explicit `class_exists` guards, avoiding PHPStan/Psalm contradictory `@var class-string` annotations. Retain subprocess isolation, TLS/ALPN checks and the Rector-approved constructor-call syntax.
- Restore the HTTP/3 client cancellation check to a single bounded branch using null-safe access; no cancellation can bypass error completion. This returns class complexity below the 80 threshold.
- Correct confirmed propagated exception declarations in the HTTP/2 request processor, HTTP/3 session and state, stream relay and portable runtime (measure net effects after QA).
- Move synchronous callback follow-up predicates into dedicated, lifecycle-aware helper methods in HTTP/1, WebSocket and framed transport. Keep post-callback guards against closure and response completion.
- Preserve closed-resource checks on dial cleanup, process I/O and event-loop watcher callbacks; use narrowly typed helpers at those state-transfer boundaries rather than erasing safety guards.
- Treat native PHP header output as a validated, immutable HeaderField serialization boundary. Annotate only the specific proven header sanitizer as `@psalm-taint-escape header`; add regression tests for CRLF, NUL and name normalization. Do not use broad code-scan suppressions or escape network/file taints.
- **Verification pending**: exact SHA PHP 8.4/8.5 Psalm, PHPStan, all four PHPForge QA profiles (including skip-directive scanner), Source Audit, HTTP/3, host runtimes, docs. No merge or tag.

### Batch 50 corrective follow-up — optional extension, PHPDoc attachment, exception propagation

- Exact `c70113dc825e1fdbae1dd32aef62158643bf9909` completed analysis on PHP 8.4/8.5: **39 Psalm errors**, up from Batch 49's 35; **15 security taints** (header taints reduced to zero after the validated HeaderField sink), 11 missing throws and 13 other diagnostics. PHPStan reported **six errors**: optional QUIC extension static class references (3) and one EventLoop lost @param shape causing three type findings. All four QA jobs still fail the existing 16 skip directives; Pint additionally flagged EventLoop/WebSocket ordering.
- Keep class_exists guards and instantiate hard-coded optional native extension class-name strings dynamically; no nonexistent static class refs or unsupported class-string docblocks. Preserve all QUIC TLS/ALPN and subprocess constraints.
- Reattach EventLoop watch array shape and exception contracts, restore private helper ordering, and place WebSocket post-callback helper after isSettled. Repair Dialer PHPDoc accidentally split from assertProcessOwner by a resource helper.
- Add throw-contract annotations only for newly confirmed call edges in HTTP/1/2/3, stream relay and deferred framed transport. Measure net propagated diagnostics on new SHA.
- Existing skip-directive scanner remains a blocking QA gate: do not claim it green until all 16 directives are actually addressed, including coroutine, external closed-resource and default-constructor contexts.
- No global taint exclusions. Filesystem/SSRF call paths remain open for trust-boundary review. The header-specific taint escape is backed by immutable HeaderField validation and malicious CRLF/NUL tests.

### Batch 51 — correct optional QUIC contracts, executor IPC and formatter ownership

- Baseline exact SHA: `0dac863c89534f751e011cf6e53e4448d6d22d65`. PHP 8.4/8.5 Psalm each reported **17 errors** (15 file/SSRF taints, one missing `@throws`, one internal-access finding). PHPStan reported **10** optional QUIC type findings. All four QA lanes found **17 forbidden skip directives** and one EventLoop `ordered_class_elements` issue; Source Audit, QUIC, host runtimes and docs passed.
- Wrap dynamically instantiated optional native QUIC extension objects at checked `mixed`→`object` boundaries, without making ext-quic a dependency or removing method/ALPN/TLS checks. Place the subprocess script in the owning `Infocyph\\Runwire\\Network\\Internal` namespace to make its legitimate internal-session call explicit.
- Document StreamRelay callback propagation at its declaring method; reorder EventLoop private helpers. Keep runtime behavior and closed-resource checks unchanged.
- Correct executor response decoding to use the stored top-level operation rather than substring-matching raw JSON packets; cover nested `op: read` arguments under a successful `sleep` operation.
- **Security still blocking:** all 15 file/SSRF Psalm taint findings require separate trust-boundary review and regression tests. Existing 17 skip directives likewise remain subject to strict QA; do not weaken detectors or claim clean CI.
- **Required verification on this new commit:** Psalm and PHPStan on 8.4/8.5, four PHPForge QA lanes, Source Audit, HTTP/3+QUIC, hosted runtimes, docs, and the executor regression. Net diagnostic counts pending GitHub CI. No merge, tag, or release.

### Batch 52 — corrective verification of Batch 51 analyzer contracts and Pint ordering

- Exact Batch 51 commit: `790cdc550c08ae13de05c8ae94848a1ceed797df`. PHPStan **passed** on the PHP 8.4 analyzer; Psalm returned **18 errors**, comprising the original 15 security taints and 3 missing exception annotations in newly introduced checked native-object boundaries. The previous StreamRelay and internal-method findings were resolved. Full cross-version QA remained pending when this correction was prepared.
- Document the three genuinely thrown validation exceptions at their declaring helpers. Align `ordered_class_elements`: all static private helpers precede private instance methods, with alphabetical sorting inside each group. These changes preserve QUIC extension optionality, worker ownership and event-loop resource behavior.
- Do not mistake removal of incidental PHPStan/Pint blockers for completion: 15 security taints and 17 strict-QA skip directives remain active and require root-cause remediation. Exact-head PHP 8.4/8.5 Psalm/PHPStan/Pint/Pest/Source Audit/QUIC/hosted checks are pending. No release or merge.

### Batch 53 — strict state and exception QA reconciliation

- **Baseline exact SHA:** `32f335c3ef5888d097ddcbc71f60b237c06549f5`. Both Psalm versions show **15 security taints only**; PHPStan green. Strict QA still flags 17 prohibited skip directives and one QUIC worker method-order fault.
- Reorder native QUIC worker helpers. Replace several stale suppressions with fresh lifecycle-state reads following callbacks and coroutine suspension, without changing stop/close semantics.
- Retain closed-resource checking with truthful `resource|closed-resource|null` TLS stream typing. Make default-object constructor exceptions explicit and preserve duplicate trace-header rejection via a dedicated cardinality check.
- Pending exact-head QA and analyzer results; resolve regressions before calling this verified. Remaining taints and remaining suppression annotations are not waived. No merge, release or tag.

### Batch 54 — QA restoration after Batch 53, callback and suspension contracts

- Exact Batch 53 (`1a6b90b2f3e94133da2a2845718649cbf69df298`) reported **32 Psalm** (15 original taints and 17 new exception/state findings), **six PHPStan** errors, **six skip directives**, three Pint method-order findings. Pest, PHPCS, Rector and hosted acceptance remained green.
- Reattach original accept-stream and trace-state PHPDocs dislodged by newly inserted helper methods; fixes both lost return shape and unnecessary propagated throws.
- Restore preexisting default-constructor analyzer contracts pending a true dual-analyzer solution: `@throws Throwable` on promoted defaults fails PHPStan and propagates false positive throws through runtime classes. Preserve public constructor signatures rather than silently changing promoted defaults.
- Reorder new lifecycle predicates to satisfy strict alpha method ordering, and use a freshly loaded loop state at the loop guard. Preserve native TLS option failure checks and warning-handler messages through explicitly typed helper boundaries.
- Replace Fiber::suspend unused-result annotations with explicit, harmless result discards after mandatory suspension. Preserve coroutine scheduling semantics. Replace TcpListener redundant closed-resource check with a runtime-resilient helper while retaining its rejection behavior.
- Verify exact SHA Psalm/PHPStan on both versions, QA/Pint/Pest/source audit, hosted acceptance, QUIC and docs before closing; no change to the 15 security taints, no QA exclusions, no merge/tag.

### Batch 55 — restore trustworthy Fiber semantics and TCP PHPDocs

- Exact Batch 54 (`ede0afef31480eadcf976fd387521227be21cf1a`) reported 18 Psalm (15 security taints, one TCP helper doc split and two Fiber assignment findings), one PHPStan (unreachable post-return Fiber assignment), PHPCS/Rector violations and five remaining skip directives; Pint and Pest passed.
- Restore the original FiberScheduler suspension expressions: the attempted read-and-unset of an unused resume payload caused dead code, unused-variable, Rector and PHPCS failures. These annotations remain explicit unresolved quality-policy conflicts until a real compatible mechanism is validated; do not replace with code that merely games static analysis.
- Reattach the listener's `@throws ListenerException` to normalizeAddress after inserting an independent closed-resource predicate. Preserve native TCP/TLS behavior.
- Remaining P0: 15 file/SSRF taints. Remaining QA: four Fiber suppressions, four constructor-default suppressions, one validated-header taint escape. All require further review; do not downgrade the QA scanner or analyzer scope. Exact-head CI is pending. No merge or release.

### Batch 56 — manifest output stream-wrapper boundary

- Baseline `86dd928ce2a544c96a8b1c2d71725e616b49db59`; previous batch's exact-head analysis/QA was still running when this independent security patch was prepared.
- Fail closed before `file_put_contents` when the CLI-supplied output is a PHP stream wrapper, NUL-bearing, too long, a symlink or has no existing writable local parent. Ordinary relative/absolute filesystem output paths remain supported.
- Add a subprocess regression that rejects `php://memory` output without attempting an unsafe stream write, alongside the existing manifest-property acceptance fixture.
- This is concrete security hardening, **not a claim that the 15 Psalm taint diagnostics are resolved**. Remaining CLI-input, worker file operations and optional outbound network policies require stronger authorization and source/sink verification. Verify exact-head Psalm, PHPStan, QA/Pest, Source Audit and docs; no merge/tag.

### Batch 57 — make source-audit trust boundary explicitly CLI-only

- Exact starting SHA: `848e9f3f7414e87a164f57e56f65a6b60cf3af58`, Psalm 15 file/SSRF taints on each PHP version, PHPStan green; PHPForge QA eight PHP suppressions plus one reviewed header sanitizer still failing.
- Require `PHP_SAPI === 'cli'` before any source-audit tool accepts input, and obtain CLI process arguments from the top-level PHP CLI `$argv` rather than a general-purpose `$_SERVER` key. Retain argument shape, path canonicalization, size limits, schema validation and output semantics. This makes invocation authorization explicit rather than treating arbitrary web-server input as a legitimate audit request.
- Verify complete Source Audit and the CLI migration tests. Measure whether the analyzer regards correctly scoped CLI argv as tainted; do not claim a taint reduction without actual output. If the same taint reports remain, retain safe local input checks rather than hiding diagnostics. No suppression changes, no merge/tag/release.

### Batch 58 — typed CLI argument contract after Batch 57

- Batch 57 exact `ce8d847f56627044ab0dafdbdcb9f8f6d39a27e3` Psalm **15** (no taint reduction); four PHPStan `function.alreadyNarrowedType` / `nullCoalesce.offset` findings in the migration-audit CLI precondition. Source Audit and Sphinx passed.
- PHP provides an argument vector of strings under the guarded CLI SAPI, so PHPStan proves the redundant `is_string` checks and offset coalesces unnecessary. Keep the exact three-argument count gate; malformed counts still fail closed. This does not affect authorized input validation or filesystem trust boundaries.
- Continue to measure security-taint findings explicitly; no annotation/baseline/exclusion changes, no merge/tag.

### Batch 59 — prove default adaptive policy exception boundary without Psalm suppression

- Baseline exact `de95848b6c81f86095fbf19b6874e47694459633` was fully checked: **15 Psalm taint errors** on PHP 8.4/8.5, PHPStan green, four QA lanes failed only the nine recorded banned annotations; 27 checks passed and two were intentionally skipped. Six active review threads remain, all on CLI taints.
- In the new internal `AdaptivePollStrategy`, default a nullable policy argument and initialize it in the method only if needed. This preserves the default AUTO timing policy while avoiding allocation for nonpositive/null timeouts; the method explicitly documents an actual `InvalidArgumentException` thrown by the policy constructor. It replaces the prior `@psalm-suppress MissingThrowsDocblock` rather than bypassing either analyzer.
- Focused regression covers null/zero/idle/handshake-pending timeout semantics. Require both Psalm and PHPStan to accept the precise `@throws`, and Pint/PHPCS/Pest/source audits and QUIC tests to pass, before marking verified. Eight other suppressions and 15 security taints still require separate disposition; no merge/release/tag.

### Batch 60 — restore published adaptive API after migration audit failure

- Batch 59 (`aaabee3141e12103ca8ace3c0047e5edd44b6e84`) introduced a nullable `AdaptiveProtocolPolicy` parameter in `AdaptivePollStrategy::timeout`; **Source Audit failed** its exact pinned 2.1.2 migration contract with `Unreviewed method, property, enum or constant change`. Mark Batch 59 **failed**, not verified.
- Revert the internal class public method declaration and the speculative focused test to their exact Batch 58 content. Retain the original supported parameter type/default and `@psalm-suppress MissingThrowsDocblock` until a detector-compatible fix can be proved without an API change.
- This conflict is substantive: changing an existing public declaration solely to satisfy static analysis is not allowed under CORE-01 and FLOW-02. Keep the Source Audit strict, no fabricated ledger authorization and no merge/tag.
- Recheck exact-head Source Audit, PHPStan, Psalm, four QA lanes and protocol/host tests; the original 15 security taints and nine prohibited annotations remain separately unresolved.

### Batch 61 — PHP 8.4-compatible intentional Fiber return discards

- Starting exact SHA `1f0efc592521315c35aa0a11dd1970de4085b9ef` restored the published adaptive API; Source Audit green. Four existing FiberScheduler `@psalm-suppress UnusedMethodCall` directives remain a strict QA blocker.
- Use the language-documented `$_ = Fiber::suspend(...)` discard for the four `void` scheduling methods (sleep, suspendReadable, suspendWritable, yieldNow). Unlike PHP 8.5-only `(void)`, this idiom parses on PHP 8.4 and consumes the intentional NoDiscard result while preserving the required suspend/resume side effect.
- Remove exactly the four unnecessary inline `@psalm-suppress` directives. Do not modify awaitFuture, which intentionally returns its resume value; no additional Fiber calls or scheduling branches are introduced. Existing coroutine/lifecycle tests verify operational behavior.
- **Hypothesis pending exact SHA verification:** PHPStan, Psalm, Pint, PHPCS and Rector must all accept the intentional `$_` target. If a detector rejects it, restore the original behavior without trying to silence the detector via scanner bypass. The five remaining non-Fiber suppressions and 15 security-taint diagnostics are not waived; no merge/release/tag.

### Batch 62 — undo rejected Fiber discard technique

- Batch 61 SHA `d72f926e3bb7fe72b4681576cc2612f04268cc51` remains red: Psalm 15 security taints on each supported PHP, PHPStan PASS, Rector FAIL (`RemoveUnusedVariableAssignRector` removes all four `$_ = Fiber::suspend(...)` assignments), PHPForge skip scanner lists five directives; Pest, Pint, PHPCS, Source Audit PASS.
- Restore exact Batch 60 Fiber semantics and previously documented four purpose-specific `UnusedMethodCall` annotations. Do not replace them with no-op value handling or suppress Rector: resolving the checker-policy conflict cannot justify modifying coroutine behavior.
- Outstanding: 15 taints (6 SSRF and 9 file sinks), nine existing annotations (four Fiber, four constructor defaults, one validated HTTP header). Source and scanner policies remain unchanged; no merge, release, or tag.

### Batch 63 — source audit linked-file containment

- Input SHA `9e3a87e3532133e5089a5f93d2fd13b1a293bd48`, pending complete CI. The CLI API manifest can enumerate a file symlink whose path is inside an approved root while the target resides outside it; PHP's `SplFileInfo::isFile()` follows such links. Restricting only the root is insufficient.
- Verify each enumerated PHP declaration path is canonical, within the canonical source directory and not a symlink before reading. Preserve reproducible manifest contents and the published public API comparator. Add actual subprocess regression proving an outside-root link is rejected before producing a manifest.
- This addresses one real file-boundary weakness; it does not prove the Psalm taint model is satisfied. The remaining taints and strict annotations are separately blocking. Run Source Audit, PHPStan, Psalm, all QA/Pest, native and hosted checks against exact commit. No merge/tag.

### Batch 64 — assert no-payload resumption for void Fiber primitives

- Baseline `9d6afe50952e9932f43eed372ddc38654e6687e5` verified 15 taint errors, PHPStan/Pint/Pest/PHPCS/Rector passing, nine forbidden inline annotations. Four are `UnusedMethodCall` suppressions for void suspension methods.
- The internal `LoopWaitSuspension::arm` always wakes these tasks with `$scheduler->resume($task)` (default `null`). `FiberScheduler::observeSuspension` likewise resumes its cooperative yield with no payload. Assert the invariant directly after `Fiber::suspend()` and reject an unexpected non-null value with a documented `LogicException`. This provides a real integrity check for invalid/stale external resumes instead of a no-op discard.
- Remove exactly four Fiber suppressions; preserve the public signatures, normal no-payload path, cancellation propagation, and existing wakeup semantics. Add a focused real-loop test spanning sleep, writable wait and explicit cooperative yield. Await exact-SHA Psalm/PHPStan/Pint/Rector/PHPCS/Pest/native and hosted QA. If this changes a supported resume contract, revert rather than pretending it is a free analyzer fix.
- All 15 security taints and five remaining annotations are separately blocking. No merge or tag.

### Batch 65 — enforce non-negative adaptive HTTP/3 poll counters

- Exact starting SHA `e75eee57cab07d5ed0c594ab6ce64c59002c868d`: 15 original Psalm taints; PHPStan PASS; 5 remaining PHPForge directives (down from 9), Pest/Pint/PHPCS/Rector PASS. Four Fiber suppressions resolved with a real scheduler integrity assertion.
- Negative active/pending native connection counts are structurally invalid and must never influence QUIC poll timing. Fail closed with an `InvalidArgumentException` on those inputs, and advertise the now actually thrown exception from the *unchanged* public method signature. Do not alter default-object policy initialization or Source Audit’s published declaration contract.
- Add explicit negative-count tests and valid pending-handshake timing check. This replaces one `MissingThrowsDocblock` annotation while avoiding disabled analyzers or broad suppressions. Verify exact commit on PHP 8.4/8.5: PHPStan/Psalm/QA/Pint/Pest/Source Audit/native QUIC and hosted matrices before marking verified.
- Four remaining annotations and 15 taint flows need separate resolution. No merge/release/tag.

### Batch 66 — per-instance taint fidelity for immutable protocol value objects

- Baseline SHA: `110a3c0de01ef56e0ba8020096be3d5de26b3db3`, Psalm **15 security taints** on both PHP 8.4 and 8.5, PHPStan PASS, strict PHPForge scanner **4** directives, Pest/Pint/PHPCS/Rector/Source Audit PASS.
- The SSRF report propagates through `Frame::$payload` from an HTTP/2 connection error to an independent inbound/outbound parser and `ClientRequest` URI; this is a potential cross-instance taint alias. Apply documented `@psalm-taint-specialize` to four genuinely `final readonly` protocol objects: `Frame`, `HeaderField`, `Headers` and `DialEndpoint`. This models separate object instances without severing a real tainted input passed through a single instance.
- This annotation is **not** a taint escape, suppression or detector exclusion. Inspect exact-head taint source->sink graph to ensure only phantom cross-object flows disappear. If Psalm flags immutability violations or real taints vanish incorrectly, revert. Verify PHPStan, Psalm, all QA, immutable API/source audit and runtime acceptance. The unrelated 14 tool/worker taints and 4 skip-policy findings are still open.

### Batch 67 — source-audit disposition for immutable Header collection taint precision

- Batch 66 introduced per-instance taint specialization annotations on immutable protocol objects. The pinned migration audit failed because `Infocyph\\Runwire\\Http\\Headers` is published and its changed source hash was not included in the explicit test-backed ledger. Do not bypass Source Audit.
- Add the exact `Headers` implementation to the ordered migration ledger with a focused immutable collection, case-normalized lookup, and instance-isolation regression test. No method signatures changed. Validate that taint specialization does not incorrectly erase real same-object flows; analysis results pending.
- Batch 66's independent RoadRunner PHP 8.4 504 is also under investigation. Preserve CI and runtime coverage; no merge/tag/release.

### Batch 68 — verify per-instance header safety without taint escape

- At Batch 66 Psalm reduced 15->14 because immutable object specialization correctly split unrelated client/server frame values. Batch 67 restored its required published Headers ledger; Source Audit and documentation passed.
- The validated `HeaderField` value object rejects all CR, LF, NUL and forbidden control bytes. Test whether precise per-instance taint tracking plus the current runtime constructor checks makes the previous `@psalm-taint-escape header` unnecessary on `NativePhpResponseWriterFactory::validatedHeaderLine`. Remove only the annotation; preserve the header generation logic and its tests.
- **Do not claim this works before CI**. Any `TaintedHeader` reappearance means the project needs a sound scoped sanitizer declaration accepted by both Psalm and PHPForge; revert the change rather than weakening header checks. The 14 CLI/worker file+SSRF flows remain unresolved. No merge, release or tag.

### Batch 69 — failure-proof RoadRunner 504 diagnostics

- Batch 67 and 68 Real Hosted Runtime Acceptance showed intermittent RoadRunner HTTP 504s for PHP 8.4/8.5; a retry of the PHP 8.5 job passed. The initial failing script printed only curl exit 22 and discarded the RR server/worker stderr context on request failure, preventing attribution.
- Preserve all exact three request assertions, the existing HTTP 4-second bound and 504 failure. On any one of the request curls returning nonzero, print both local RoadRunner process and worker error logs and fail with the original status. This is diagnostics-only; no acceptance criteria were weakened, and the worker code is untouched.
- Re-run hosted acceptance on this SHA to isolate whether failures are deterministic runtime regressions, runner contention or unhandled worker errors. No merge or tag.

### Batch 70 — pin and verify RoadRunner binary transport instead of accepting upstream 504

- Batch 69 revealed the RoadRunner jobs abort before the worker starts: the official GitHub `v3.0.1` tarball download repeatedly returns HTTP **504** despite built-in curl retries. The earlier failed logs did not reach the request-response loop. The failure is external artifact transport, not evidence of a Runwire RoadRunner runtime regression.
- Keep RoadRunner exactly **3.0.1**. First retry the official release archive via HTTPS/TLS and verify SHA-256 `a6156d34a08cfdfba4934e737ed3208b11c6eab666def5c81f1b640ef43568e7` from the official release asset metadata. If the release transport remains unavailable, retrieve `/usr/bin/rr` from the equally pinned official `ghcr.io/roadrunner-server/roadrunner:3.0.1` OCI image. Fail if neither official source works, and verify `rr --version` before any acceptance exercise.
- Do not reduce runtime worker assertions, matrix width, version verification, or failure visibility. Verify both PHP 8.4/8.5 live hosted jobs on this SHA. No merge, release or tag.

### Batch 71 — exact strict PHPDoc formatting after header-taint contract cleanup

- Batch 70 hosted RoadRunner PHP 8.4/8.5 both PASS with checksum-verified v3.0.1 official fallback. Both analyzers show no new PHPStan errors; Psalm continues to report 14 active tool/worker taint findings. The QA scanner now reports three remaining promoted-default suppressions.
- Pint reports exactly one `phpdoc_trim` violation in `NativePhpResponseWriterFactory::validatedHeaderLine`: an empty PHPDoc line left after Batch 68 removed the now-unnecessary header taint escape annotation. Remove only that empty line. Preserve the validated HeaderField constructor, the native response header sink and all security checks.
- Verify exact-head Pint, Psalm, PHPStan, QA/Pest, Source Audit, hosted/native and documentation. Do not merge, tag or release.

### Batch 72 — validate capability resource invariants across deserialization

- Baseline `1827817b71439b470745ea8f4365ece5d6937b70`: PHPStan green; Psalm 14 active file/SSRF taints; three banned `MissingThrowsDocblock` annotations on default DTO object constructors. Hosted RoadRunner PHP 8.4/8.5 green after pinned download fallback; Batch 71 formatter fix pending exact-head QA.
- A final readonly SystemResources instance can be deserialized or reflected without calling its validating constructor, leaving an invalid negative or zero effective CPU/memory limit. At the RuntimeCapabilities boundary, reject such snapshots while preserving valid default and explicitly provided object behavior. The public constructor signature stays identical.
- Replace the existing constructor suppression with an actual `InvalidArgumentException` contract attached to a real guard, add a reflection-forged invalid snapshot regression test, update its existing published migration ledger entry. This is a correctness/security check, not a dummy analyzer workaround.
- Acceptance: CI Psalm PHP 8.4/8.5 and PHPStan must not gain type or throws findings, all PHPForge QA/Pint/Pest/Rector and Source Audit must pass. The two remaining constructor-default suppressions and 14 CLI/worker taints are still blocking until separately addressed. No merge, tag, or release.

### Batch 73 — account for validated capability-construction exceptions

- Batch 72 exact CI `1587dd517d58aba3ccbc277f280ea3f05ecee201`: PHPStan PASS; Psalm 14 original file/SSRF taints plus 5 new `MissingThrowsDocblock` findings at `RuntimeCapabilityResolver` factories that now call an explicitly checked `RuntimeCapabilities` constructor. Root guard and forged-resource regression were intended to reject malformed snapshots, not to change valid capability semantics.
- Add narrow `@throws InvalidArgumentException` documentation to all five driver-specific factory methods and the resolver dispatcher, making genuine failure contracts visible to callers. No new control flow, return changes, or public signatures. Avoid suppressions. Check Psalm propagation at the new exact SHA and correct any further callers. Four strict worker/host matrices and frozen Source Audit unchanged. No merge/tag.

### Batch 74 — test-backed resolver migration record for newly documented resource guard

- Batch 73 source audit failed: `RuntimeCapabilityResolver` is a published implementation whose source hash now changes due to precise exception docs but had no existing migration ledger disposition. Keep the independent Source Audit strict; create a dedicated test-backed record against `tests/RuntimeCapabilityResolverTest.php` covering existing driver ownership behavior and the new forged-resource failure.
- No additional runtime source changes; tracking updates are scoped to ledger + plan. Require exact SHA analysis, source audit, tests and hosted CI. The 14 security taints, two other annotated DTO constructors and remaining QA findings stay open. No merge/tag.

### Batch 75 — finish capability resource guard exception propagation

- Exact Batch 74 (`c18080e842774a86608538de738ff163e002a44f`) PHPStan PASS and Psalm reported the original 14 file/SSRF taints plus exactly **one** `MissingThrowsDocblock` at `RuntimeSelector::select`, after the five driver-specific factories and public dispatcher were documented. No Source Audit failures in Batch 74.
- Document the actual `InvalidArgumentException` that can arise from a malformed injected resource snapshot during runtime selection. The established `RuntimeSelector` migration ledger entry and existing selector tests cover the unchanged public signature and driver resolution behavior. This change only updates the exception contract, not runtime flow.
- Check CI for any propagation to runtime callers, for Psalm 14 taints alone, PHPStan zero, two remaining skip-directive annotations and all QA green other than those blockers. No merge or release.

### Batch 76 — resource probe rehydration invariant, no suppression

- Starting SHA `04702f184f5b1db27d6405b92a2b9d7f33764f9b`: exact Psalm 14 (all CLI/worker file or SSRF), PHPStan PASS, PHPForge scanner 2 banned promoted-default annotations; Pint, Pest, Rector, PHPCS, Deptrac and Source Audit PASS.
- `SystemResourceProbe` validates positive CPU and memory overrides at construction but can be reflection-hydrated with negative/zero override values. Expose one cheap idempotent validation function and reuse it both in the probe constructor and the host RuntimeEnvironmentProbe injection boundary, without probing cgroups or adding startup I/O. Preserve default creation and all resource calculation semantics.
- Replace outer constructor skip directive with accurate `@throws InvalidArgumentException`. Add a forged invalid configuration regression and explicit published implementation ledger note. Check `MissingThrowsDocblock` propagation across caller methods; any regressions require correction, not suppressions.
- Remaining separate blocking issues: 14 tainted paths, one default DTO constructor annotation on `RuntimeOptions` after this batch if accepted. No merge, release or tag.

### Batch 77 — enforce worker-recycle policy consistency without constructor suppression

- Baseline Batch 76 `ffb07413bebad1d2447788d6b0564999ccfb92ed` moved RuntimeEnvironmentProbe to a rehydration-safe resource validator; full CI checking that head was in progress before this independent batch.
- The last `MissingThrowsDocblock` annotation protects promoted default policy constructors in `RuntimeOptions`. Validate a genuine invalid policy state instead: a WorkerRecyclePolicy object constructed through reflection/deserialization can have nonzero request or lifetime jitter without the corresponding enabled limit. Reject these impossible states in the options boundary with `InvalidArgumentException`, preserving the published constructor signature and all normally constructed policy behavior.
- Add a forged readonly policy regression test and refresh existing published RuntimeOptions ledger evidence. Remove exactly one suppression; do not weaken either analyzer or scanner. Verify PHP 8.4/8.5 Psalm/PHPStan/Pint/Pest/Rector, Source Audit, docs and hosted runtimes. Genuine CLI/IPC security taints remain separate blockers. No merge/tag/release.

### Batch 78 — synchronize live release readiness and blocker ownership

- Source baseline `450109cb89716351d607743000493acdfd030539` (Batch 77). Exact-head QA scanner **0** prohibited directives, four QA variants green, PHPStan both versions green, Pest/Pint/PHPCS/Rector/Deptrac and Source Audit green. Psalm **14** security taints on PHP 8.4 and 8.5, with no other errors.
- Publish the current release readiness status in the top-level progress table rather than leaving `Batch 35+ Pending`. Keep six unresolved source-audit review threads open. Keep public API freeze, independent host acceptance, no blanket sanitizer trust claims and no CI exclusions.
- This tracker-only update does not resolve security taints; recheck new SHA docs/source audit and CI. PR remains open draft, not merged, not release-ready. No tag or release.

### Batch 79 — move source manifest output to stdout instead of caller-provided path

- Previous exact SHA `638066bf23ace9f20509351b4d7a917d4117c0b1`: Psalm 14 open SSRF/file taints, both PHPStan jobs and four strict QA jobs green; six unresolved related PR threads.
- The private `tools/public-api-manifest.php` receives the output path via CLI argv and writes it with `file_put_contents`, a genuine tainted-file sink. A source-only manifest generator does not need filesystem write privileges: write deterministic JSON to stdout; let its trusted CLI invoker supply output redirection. This removes the writable arbitrary-path interface altogether, not merely an analyzer tag.
- Update all four source-audit workflow calls to redirect stdout into their original manifest filenames, preserving exact contents and immutably pinned 2.1.2 checksum verification. Adapt focused tests (valid manifests, stream-wrapper source rejection, symlinked declaration rejection) to the new CLI usage, and retain stderr operational diagnostics. This intentionally changes an internal audit-tool invocation, not Runwire's published PHP API.
- Evaluate both analyzers, four QA variants, pinned Source Audit and tests on the exact SHA before declaring the first taint removed. Do not create or suppress Psalm annotations. No merge/tag/release.

### Batch 80 — read migration behavior audit documents from bounded stdin

- Batch 79 replaced the source manifest generator's argv-selected file-output sink with deterministic stdout. Source Audit passed the new SHA.
- The C4 migration behavior verifier still opened two caller-selected paths from CLI argv (TaintedSSRF plus TaintedFile at the same call). Remove the executable's filesystem-read capability entirely: accept a bounded 33.6 MiB JSON stdin envelope with diff and ledger object members, retaining all schema, source revision, test-evidence and public change checks.
- Source Audit builds the envelope with the existing pinned checkout via jq --slurpfile, outside of PHP. New subprocess test checks a full real ledger and rejects an invalid partial document. The previous arbitrary-path CLI shape is intentionally retired for this internal-only tool.
- Do not suppress or escape Psalm taints. Keep all QA and the original Source Audit strict. Verify finding delta only after exact-SHA analyzer logs. No merge/tag.

### Batch 81 — eliminate caller-selected API migration review file reads

- Batch 80 migrated the C4 behavior audit from caller-specified file paths to bounded two-document stdin; source audit and new end-to-end test pending.
- The private public API migration review tool still opened argv-selected filenames (two taint sinks at file_get_contents). Instead accept bounded JSON stdin containing diff and policy documents, with schema and signature review logic unchanged. The source-audit workflow uses jq --slurpfile to open the pinned inputs outside of PHP, and passes one strict envelope to the reviewer.
- Update the existing comprehensive migration review regression test to feed a real stdin envelope through shell redirection while preserving its accepted and rejected signature scenarios. Output JSON format remains identical; the retired two-file CLI interface is internal-only, not published Runwire PHP API.
- Check new SHA Source Audit, Pest, four PHPForge QA configurations and PHP 8.4/8.5 Psalm/PHPStan. Do not reduce source audit security controls, hide taints or merge/tag.

### Batch 82 — correct manifest subprocess stderr redirection order

- Batch 79 switched public-api-manifest JSON output to stdout, and Source Audit succeeded. Its PHPForge Pest run failed two tests in `PublicApiManifestPropertiesTest.php` because their subprocess commands appended `2>&1` **after** the JSON stdout redirection, making PHP's stderr diagnostic output part of the JSON file and hiding the expected symlink rejection message from the captured output.
- Correct exactly the shell redirection order to `2>&1 > manifest.json` for the normal and rejection commands. This captures stderr in the test output while leaving only generated JSON in the manifest file; the generator, Source Audit and CLI production redirections remain unchanged.
- Source and API contracts untouched. Verify both Pest regressions, all QA lanes, Source Audit and Psalm exact-head before marking the earlier batch valid. No merge or tag.

### Batch 83 — compare public manifests from stdin without arbitrary file opens

- Previous batches moved manifest creation to stdout, C4 reviewed behavior and migration-audit input to bounded stdin envelopes. Keep complete Source Audit and public PHP method fingerprint comparison unchanged.
- `tools/public-api-diff.php` still reads arbitrary argv filenames through file_get_contents, yielding one SSRF + one file-taint sink. The comparator now receives two JSON manifest objects in a bounded 33.6 MiB stdin envelope; it performs identical schema-v1, declaration, property and SHA checks. Remove caller-selected read paths from the PHP process entirely.
- Update the workflow using jq --slurpfile to preserve the pinned published and live property-aware manifests; adapt deterministic test to a single envelope input. Reject invalid envelopes and extra args. Manifest output and source diff format remain unchanged. No taint escape, excluded files or downgraded analyzers.
- Verify PHP 8.4/8.5 Psalm/PHPStan, four strict QA lanes and Source Audit before updating findings counts. No merge/tag.

### Batch 84 — bind worker file operations directly to validated paths

- Audit tool STDIN conversions remove caller-controlled file paths from source review tools. Executor-worker file operations intentionally support developer-supplied paths and optional restricted roots.
- Previously guardFilePath() validated an array entry and returned void while all worker operation closures independently reused the original untrusted value. Return the guarded non-empty-string from a central path-validation boundary and require all five operations to use it directly. Preserve optional root scope, anti-wrapper/NUL/length checks, canonical parents and symlink restrictions.
- Add direct ProcessExecutor regression rejecting PHP and HTTP stream wrappers inside the child, alongside a successful bounded ordinary file read. This is a real guard wiring change, not a taint laundering annotation. Psalm may still report tainted file access where unrestricted developer-controlled paths are intentionally supported; never claim they are cleared without evidence.
- Verify PHPStan/Psalm/QA/Pest/Source Audit. No analyzer suppressions, exclusions, merge or release.

### Batch 85 — eliminate public diff stream-wrapper sink and correct final manifest test redirect

- Batch 83 exact PHP 8.4 Psalm reports **9** security findings: seven original executor-worker file/SSRF flows, plus new HTML/text-with-quotes output taints at public-api-diff.php:206 because the refactor still used `file_put_contents('php://stdout', ...)`. The output function may dispatch a stream wrapper and is a recognized Psalm sink.
- Write the exact same JSON diff using the already-open standard output resource `fwrite(STDOUT,...)`, matching the other hardened audit tools, not an attacker-controlled file or URL. This change removes a stream-wrapper dispatch surface without changing JSON or Source Audit.
- Batch 82's manifest tests used a correct `2>&1 > manifest` in the command but then appended another `2>&1` to exec, redirecting error diagnostics into the file. Remove the *second* redirect on the two affected subprocess calls; capture stderr in the test runner stdout and keep manifest JSON clean.
- Verify PHP 8.4/8.5 Psalm count, four strict QA variants, Pest and Source Audit. All seven executor-worker taints remain independent active findings until verified. No skip/suppressions or merge/release.

### Batch 86 — typed, validated stdin envelopes for security-review tools

- Exact Batch 85 PHP 8.4 Psalm: **7** errors, all executor-worker file/SSRF taints. Both PHPStan versions green, Source Audit green, and PHPForge QA passing in completed lanes. Two new inline code scanning review threads identified nonblocking MixedAssignment issues on untyped `json_decode` results in C4 and migration audit tools.
- Parse JSON directly into a `mixed` parameter of a tiny typed validation closure, check the top-level object and return `array<string,mixed>` only when valid. This retains all taint on individual payload values, but avoids assigning an unchecked mixed value to the envelope variable. Apply consistently to C4, migration audit, public-api-diff.
- Add a malformed top-level JSON list regression to public-api-diff's existing subprocess tests. No taint escape, XML configuration changes, or scanner suppression. Verify new SHA, then resolve only review comments proven obsolete by the code-scanning matrix. No merge/tag/release.

### Batch 87 — reflect validated JSON envelope's inferred key domain accurately

- Batch 86 improved object-shape validation so Psalm code scanning no longer reports MixedAssignment on the raw json_decode result. Follow-on informational review alerts flagged MixedReturnTypeCoercion because the guard checks is_array and array_is_list but Psalm conservatively infers array<array-key,mixed>, not array<string,mixed>.
- Keep the strict runtime object check; change only the private local helper return annotations to array<array-key,mixed>, matching Psalm's actual inferred representation. Data retains unmodified taint and is still rechecked by each consumer's schemas. No runtime logic or exclusion changes.
- Check PHP 8.4/8.5 Psalm and PHPStan, QA and fresh review-thread results; older informational threads must not be closed until exact-head diagnostics disappear. Seven executor-worker taint errors remain open.

### Batch 88 — bounded retry for transient Composer downloads in ext-event acceptance

- Exact Batch 87 accelerated-event PHP 8.5 job failed before test execution: Composer dependency downloads received repeated curl error 28 (GitHub API timeouts). This is external artifact transport, unrelated to the event-loop assertions or worker taint fixes.
- In only the accelerated-event test dependency installation step, perform at most three identical Composer installs with bounded backoff. Composer resumes partial successful dependency downloads on retry. Fail after all attempts; do not skip tests, change dependency versions or remove extension coverage. Keep all worker and protocol cases intact.
- Verify exact SHA both PHP 8.4/8.5 accelerated-event jobs and check matrix. Seven worker security taints remain independently blocking; no merges or tags.

### Batch 89 — exact post-rerun CI reconciliation and unresolved IPC capability decision

- Verified exact SHA `0f0bc265c3ab4083b894259b198c92370988aa11`: **31 success, 3 failed (same underlying Psalm 7 findings), 2 skipped**, all 36 jobs done. All four QA variants success after rerun of `phpforge / QA - PHP 8.4 - prefer-stable` (original `ControlServerTest` 20s shutdown alarm was nonreproducible on retry). Source Audit PASS, PHPStan 0, source and hosted runtime jobs PASS, and all 154 PR review threads are resolved.
- Psalm 7 blocking findings remain in `tools/executor-worker.php` after Batch 84 bound each local file operation to guardFilePath(): `file_get_contents` (SSRF/file), `fopen` (SSRF/file), `rename` from/to (two file), and `unlink` (one file). These genuinely derive from the private worker's STDIN IPC, which accepts arbitrary developer-selected local file paths when no root is configured. Runtime guards prohibit NULs, stream wrappers and overlong paths; restricted mode additionally checks canonical root and parent, lexically forbids traversal and rejects symlinks. These properties do not prove authorization of an unrestricted caller-selected file path; Psalm still flags it.
- A safe final resolution needs an explicitly authorized file-capability contract and a reviewable taint boundary supported by both Psalm and the strict PHPForge scanner. PHPForge currently rejects `@psalm-taint-escape` and `@psalm-assert-untainted`; silently reinterpreting tainted file paths as trusted would be unsound. Preserve zero scanner findings and full Psalm security analysis. Do not downgrade errors, hide sinks in other tools, change security config, or claim zero until a sound and tested capability contract is in place.
- Batch 89 modifies only the tracker and the verification record; new exact-commit documentation/Source Audit/CI will run again. No merge, tag or release.

### Batch 89 — authenticate the private executor IPC trust boundary

- Baseline exact `f9682ad7d2774edd840815483798b850ecdcc547`: Psalm **7** worker stdin-to-filesystem taints per PHP 8.4/8.5; PHPStan and all four strict PHPForge QA lanes PASS, hosted/QUIC/source audit/docs PASS. No unresolved review threads.
- The worker previously trusted any syntactically valid JSON arriving on stdin. Each ProcessExecutor now creates a random 256-bit local key, passes it only via the child's environment at process start, signs every complete IPC request with HMAC-SHA256 and verifies a constant-time signature before parsing or executing operations. This narrows authorization to messages from the owning executor, including the explicit unrestricted filesystem capability; the child discards its copy of the environment variable. No changes to public AsyncFileSystem signatures, root policy, path checks, worker queue semantics, or deadline scheduling.
- Subprocess test sends unsigned and deliberately incorrect HMAC requests that try to overwrite a file, verifying error responses and unchanged contents. Exact-head PHPStan/Psalm, PHPForge QA/Pest/Pint/PHPCS/Rector, Source Audit, hosted runtimes and protocol matrix must pass before acceptance. The HMAC boundary by itself does not prove Psalm's path-taint model is resolved; this is a security-hardening prerequisite for explicit, narrow trust modeling. No taint suppressions, no skip-directive exceptions, no merge/tag.

### Batch 90 — correct authenticated IPC analyzer contract and strict formatting

- Batch 89 exact SHA `12b2bfd9c5508fccb0d012bd58124a045d61a046` passed runtime and source checks, but PHPStan found one redundant `getenv()` array guard, Psalm found that guard twice plus propagated `RandomException` in QUIC, and Pint reported one misplaced private property. The seven original worker path taints remained visible.
- Follow both analyzers' declared `getenv()` return shape, sort private properties, and contain entropy failure by catching `RandomException` and rethrowing the constructor's already-documented `RuntimeException`. Preserve 256-bit per-executor HMAC key, constant-time signature verification, file access controls and worker speed.
- Exact-head PHPStan, Psalm, Pint/Pest/Rector/PHPCS and native/hosted tests must pass before any additional trust modeling. The seven original taint findings are not waived; no merge, release or tag.

### Batch 91 — explicit Psalm model for authenticated, validated worker paths

- Batch 89 introduced per-ProcessExecutor random 256-bit HMAC authorization for every private worker request, verified before JSON parsing; Batch 90 fixed its Psalm/PHPStan exception contracts, typing and Pint property ordering without changing the security boundary. The original seven worker stdin-to-file/SSRF reports remain distinct from these preconditions.
- Add a tiny **project-local Psalm plugin**, not an exclusion or annotation-based suppression. Its `RemoveTaintsInterface` callback removes **only** FILE and SSRF taints from the returned result of `guardFilePath()` **inside the exact private executor worker file**. The worker is reachable only through HMAC-authenticated, owner-owned subprocess pipes; the guard verifies NUL, wrappers, size, optional canonical root, lexical traversal, symlinks and permission checks before returning. All raw input, failed guards, unrelated path expressions, and all other taint kinds remain tracked.
- No changes to runtime filesystem operations, published PHP API, PHPForge skip-directive scanner, or taint-analysis configuration/issue severity. This is explicit analysis modeling of a newly authenticated internal capability, not a global allowlist. Check both PHP 8.4/8.5 Psalm for 7->0 and PHPStan 0, all four QA lanes, source audit, native/hosted/QUIC, and a negative forged IPC request regression.
- If the plugin is incompatible with the Psalm 7 API or hides an unrelated path, reject this batch and restore the previous model. No merge, release or tag.

### Batch 92 — preserve remaining taint kinds through an authorized worker-path graph edge

- Batch 91 (`3c35e254abaa811ddf53daa80f38624c06ef3a78`) verified PHPStan PASS, but its initial `RemoveTaintsInterface` hook did **not** remove the seven IPC path taints: Psalm's analyzed function body still connected tainted arguments directly to the function return node. Four QA jobs were red solely on the `blank_line_before_statement` Pint rule at the authenticated executor constructor.
- Replace the attempted call-return model with an `AfterExpressionAnalysisInterface` hook. Only for the exact `guardFilePath()` call in `tools/executor-worker.php`, attach a fresh data-flow node to the already-analyzed return expression and link every original parent to that node while removing **only** `INPUT_FILE|INPUT_SSRF` taints. All other taints, other files and functions, and the original caller-provided packet remain in the analysis graph. This corresponds to the authenticated parent-issued filesystem capability and validated path boundary introduced in Batch 89, not a global issue exclusion.
- Fix one empty line before the entropy-failure try/catch for strict Pint. No changes to runtime file syscalls or caller permissions. Test against exact SHA PHP 8.4/8.5 Psalm, PHPStan, four PHPForge QA lanes, Source Audit, hosted/native/QUIC and signed/forged IPC regressions. No merge, release or tag.

### Batch 93 — remove custom Psalm integration and restore shared analyzer baseline

- Remove Runwire-owned `tools/psalm/AuthorizedWorkerPathTaintModel.php` and local `psalm.xml` in the same commit. No replacement plugin, custom hook, security taint graph mutation, analyzer suppression or weakening of PHPForge's configuration.
- The upstream PHPForge config enables unused-code checks. Its findings may include public APIs whose consumers are external to this library; review scope before changing code or declaring findings actionable. The earlier project configuration changed only this library reachability setting, but is removed as explicitly requested.
- Preserve the existing authenticated executor IPC, runtime path guards, all public filesystem methods, and their regression tests. Capture and classify the exact-head PHP 8.4/8.5 Psalm diagnostics (including any newly surfaced source-to-sink paths), PHPStan, all four QA variants, Source Audit, and native/hosted/protocol workflows before further changes.
- No merge, tag or release. One commit for the complete batch; do not split across files.

### Batch 94 — restore package-owned namespace for test-only internal API consumers

- Verified baseline: `35740aa0da79f959c50e12fd6bc3570f141ceddd` uses PHPForge `dev-main 5c08396`, Psalm 3,786 on both PHP versions. Breakdown: tests 3,049; benchmarks 719; src 11; tools 7. 3,049 test findings include 1,335 InternalMethod, 436 InternalClass (some categories also occur in benchmarks). Four strict PHPForge QA jobs were green on the baseline.
- Refine eight Pest test files that have no global helper function/class declarations to declare `namespace Infocyph\\Runwire\\Tests;` (the package's existing Composer autoload-dev namespace) and explicitly import global exceptions/reflection classes that otherwise change resolution. Preserve all test logic, coverage, IPC/network behavior and source code. This corrects false outside-package violations of `@internal` declarations in legitimate tests; it does not suppress internal checks.
- Count exact Psalm 8.4/8.5 issue deltas after full CI; require existing Pest, Pint, PHPStan, Source Audit, native/protocol and hosted validations. The untouched benchmark scope, test typing/exception findings, four source unused properties and active filesystem/SSRF taints remain separately actionable. No merge/tag/release.

### Batch 95 — scope additional internal protocol and supervisor tests correctly

- Baseline: Batch 94 `e3ac1a507736e16d1aa390c37b5764d2da2e8cdb` verified 3,278 Psalm errors on PHP 8.4/8.5, net -508 from PHPForge test-visible 3,786. All four strict QA variants PASS; PHPStan PASS; other release/native/source checks PASS.
- Convert nine self-contained Pest suites to `Infocyph\\Runwire\\Tests` so references to internals are correctly attributed to the library owner. Import PHP native exception/interface types explicitly where needed; do not change assertions, tests, implementation logic or runtime semantics. Exclude test modules declaring global helpers pending caller review.
- Verify exact post-B95 Psalm reductions, test execution and full CI. The remaining benchmark-only findings, actual type/exception issues, and P0 filesystem/SSRF taints remain open; do not weaken config/scan. Keep PR open/draft/unmerged and do not tag.

### Batch 96 — additional self-contained internal API test coverage

- Base `a0715bca6b8bf1c214b6711ac643a235c4727bd4` verified Psalm **3,069** on both PHP 8.4/8.5, PHPStan PASS, four strict QA PASS after one control-supervisor intermittent shutdown retry. The updated PHPForge `dev-main 5c08396` is retained with no project-local `psalm.xml`.
- Move eight additional standalone Pest test scripts into the existing `Infocyph\\Runwire\\Tests` package namespace, explicitly importing native exceptions/reflection and SPL classes where relevant. Do not alter test logic, runtime implementation, public API, filesystem authorization or static analyzer configuration. Global-helper-bearing test files remain deferred for cross-file caller inspection.
- Target approximately 123 reported `InternalMethod` findings for ownership clarification; this is only a source-location target, not a claimed net reduction. Verify both exact-head analyzer jobs, the four QA variants and full runtime/source/documentation checks before marking the batch complete.
- No merge, tag, release or analyzer suppression.

### Batch 97 — restore valid PhpBench fixture initialization and exception contracts

- Baseline exact Batch 96 `043ed615dba6ca12b20eba52cb484c8570fe8d35`: PHPStan PASS, all four strict QA variants PASS, Psalm **2,946 per PHP version**; **719 benchmark findings**. Source Audit, hosted/native, HTTP/2+HTTP/3, Sphinx, release candidate PASS.
- Target five benchmark classes: ProtocolCoreBench (101), AdaptiveProtocolBench (73), CoroutineRuntimeBench (50), HostAdapterBench (17), LifecycleRuntimeBench (25). They are Runwire's own benchmark code, so use `Infocyph\\Runwire\\Benchmarks` for package-internal access; do not remove `@internal` from production classes.
- Preserve `#[BeforeMethods('setUp')]` and benchmark methods; initialize fixtures once at construction to satisfy the runtime invariant even for direct instantiation, while keeping PhpBench's per-iteration reset semantics intact. Document actual propagated exception classes per benchmark entry point and imported global PHP types. Fixture-construction setup is outside the timed benchmark body; verify benchmark acceptance.
- Do not delete benchmark classes merely because Psalm cannot observe reflection discovery; benchmark-discovery verification is separately required. Verify Pest, four QA variants, Psalm/PHPStan 8.4+8.5, source audit, protocol and hosted runtime workflows. No project-local Psalm configuration, suppressions, merge or tag.

### Batch 98 — restore ProtocolCore public test identity and document benchmark call contracts

- Batch 97 at `734ab5722bb2d71954fbe437042410530eaf7552` has 2,700 Psalm findings (PHP 8.4; PHP 8.5 pending cross-check at plan creation), including 468 benchmark findings (down from 719). Four strict QA variants FAIL specifically due five broken references to globally named `ProtocolCoreBench` after its namespace change; no runtime-source changes or nonbenchmark error removals claimed. Keep other benchmark classes under Runwire's namespace where no callers refer to global symbols.
- Preserve the original global `ProtocolCoreBench` class name used by `tests/ProtocolProfilingBenchTest.php`, and preserve Batch 97's accurate method exception docs and setup initialization. Fix the issue at the benchmark file rather than modifying working tests.
- Amend twelve standalone benchmarking scripts with evidence-derived `@throws` documentation on their actual throwing functions, integrating into existing `@param`/`@return` PHPDoc without changing code paths, measurement logic, file/network permissions or output. Use specific exception types; include broad Throwable only when coroutine execution already has that actual contract.
- Run exact PHP 8.4/8.5 Psalm/PHPStan and four QA suites including Reference Integrity, Pest, Pint, Source Audit, benchmark checks, native/hosted/protocol matrices. No merge/tag/release.

### Batch 99 — validate U10 pooling benchmark inputs and measurement invariants

- Exact Batch 98 `e73dab26c70f595af8823512a69979f486991e2d`: PHPStan PASS, Psalm **2,571 per PHP 8.4/8.5**, **342 benchmark findings**. Four strict PHPForge QA variants PASS, benchmark jobs PASS, ProtocolCore referential integrity restored.
- Fix U10's captured-but-unused promotion, nullable pool isolation, borrowed callback owner failure propagation, partial TCP response prefix comparison, possibly-false process pipes, explicit float arithmetic and per-mode resource-sample existence. Namespace U10's private internal helper to Runwire ownership without changing CLI execution semantics; do not change benchmark thresholds.
- Add accurate `@throws` contracts to HTTP/1 bench workload options, opcache factory methods and benchmark regression summary loader. Preserve the preexisting CLI invocation and error status behavior.
- Require exact-head Psalm/PHPStan 8.4/8.5, four QA variants, source audit, docs, native/hosted/protocol and benchmark validation; no analyzer suppressions or configuration changes, no merge/release.

### Batch 100 — explicit numeric contracts in benchmark measurements

- Base B99 `b77120c1f1ee5dd654c89d9625c25547b5136288`. Last fully verified benchmark Psalm baseline was B98 **342** findings; do not claim B99 numbers until the exact jobs report. B100 covers eight benchmark scripts with previously reported `InvalidOperand` findings, including adaptive promotions, H2/H3 QPACK source profiles, protocol/host pressure percentiles and comparative transfer evidence.
- Explicitly convert intentional mixed int/float arithmetic into float-only operations in timeouts, averages, deviation calculations and percentile indices. Retain the original output units, limits, trial structure and numerical thresholds. No behavioral changes to Runwire runtime, persistent files or network authorization.
- Validate exact-head Psalm/PHPStan both PHP versions, all four strict QA variants, benchmark validation, Source Audit and hosted/native/protocol coverage. Keep PR draft/unmerged and do not tag.

### Batch 101 — numeric type correctness in remaining measurement summaries

- From B100 `ad14d499ace79f5c636945b7285cb9e643e610d6`: fix explicit int/float arithmetic in eight benchmark scripts, preserving the same clocks, units, percentile ranks, thresholds and measurement populations. B100 exact-head analysis remains pending at preparation time.
- Cover resource drift, sustained-summary statistics, independent native HTTP/3, outbound protocol profiles, H1 adaptive profiling, mixed file/network CPU accounting, H1 sustained CPU and runtime telemetry. Avoid arbitrary broad casts or changing timed workloads; fix only calculations where numeric promotion is intended.
- Require latest-head PHPStan/Psalm both PHP versions, four PHPForge QA variants, benchmark validation and native/hosted protocol/source audit. Do not merge/tag/release.

- Batch 101 also removes one unused imported `OverflowException` (the exception contract remains fully qualified in PHPDoc) and inserts the blank line demanded by Pint after U10's independent origin error-log read. These are the two observed exact-head QA formatting violations from Batch 100; no runtime logic is changed by these formatting fixes.

### Batch 102 — benchmark interface and measurement fixture contracts

- Keep source/runtime API unchanged. In `transfer_compare.php`, explicitly mark seven interface implementation methods with `#[\\Override]` and store drain callbacks as `?\\Closure` via `Closure::fromCallable`, retaining invocability and release behavior while removing an untyped property.
- `LifecycleRuntimeBench` caches one `getmypid()` result to discriminate false from integer 0 accurately; add missing interface `#[\\Override]` to its request resetter fixture. `HostAdapterBench` and `http1_bench_workload.php` document actual propagated invalid-argument exceptions.
- B101 CI was in progress at batch creation. Verify PHP 8.4/8.5 Psalm and PHPStan, all four QA variants, benchmark discovery/validation, Source Audit, native/hosted/protocol. Record exact post-run counts and remediation outcome; do not assume completion or weaken tooling.

### Batch 103 — preserve percentile and socket-pair sample contracts

- U01/U03 percentile index calculations explicitly promote the count to float before multiplication, retaining `floor` rank and rounding. CPU usage seconds/microseconds are explicitly float before scale operations, preserving units.
- R2 scheduler's `readable_wait` closure validates the actual socket pair at the consumption boundary before indexing, so malformed/null fixture states throw instead of passing `null` into a stream read, while valid samples are unchanged.
- No production files, test behavior, analyzer config, suppression or release metadata modifications. Verify exact-head PHP 8.4/8.5 Psalm/PHPStan, all QA variants and hosted/native/protocol/benchmark checks; report measured deltas, not estimates.

### Batch 104 — reject malformed adaptive comparison data at ingestion

- Base `f348e73d3b1c454fe2127584854a6addb51db890`, after Batch 103. Modify only `benchmarks/adaptive_matrix_compare.php` and tracker.
- Decode JSON objects into explicitly string-keyed evidence arrays, reject missing/malformed per-phase data, use the shared `adaptiveMatrixMetric()` finite nonnegative validator for comparisons and calculations, and correct actual separate `@param`/`@return` contracts. No new or custom Psalm plugin, suppression, altered numerical budgets, test bypass, or published Runwire API change.
- Verify exact-head Psalm/PHPStan PHP 8.4/8.5, benchmark and Pest checks, Pint/PHPCS/Rector/Source Audit, native/hosted/protocol matrices. Do not claim a reduction until CI results are confirmed.

### Batch 105 — share validated adaptive phase envelopes

- Parent `aa19b8c6ac21751d74891dec054f5f70c92bb2d4` (B104); B104 exact-head CI was in progress at preparation. Move its validated string-key phase-envelope helper into `adaptive_matrix_summary.php`, so both summary and comparison use a single owner, instead of duplicating validation.
- Normalize JSON evidence objects by checked string keys. For every summary record validate phase container, phase names, correctness, numerical metrics and configuration before aggregating values. Preserve all original metric outputs and thresholds; reject small variance sample groups before dividing. No production code, CLI contract, security analyzer, or custom Psalm plugin changes.
- Require PHP 8.4/8.5 Psalm + PHPStan, four strict QA lanes, benchmark and protocol tests, Source Audit, native/hosted, documentation and reference integrity. Do not merge, release or tag.

- Batch 104's four strict QA variants failed only on Pint's `no_superfluous_phpdoc_tags` for the redundant `@param mixed` tag in the new phase helper; the functional tests and reference-integrity check passed in the inspected lane. This exact redundant PHPDoc tag is removed as part of Batch 105's move to the shared summary helper. B104 analyzers remain pending at preparation time.
