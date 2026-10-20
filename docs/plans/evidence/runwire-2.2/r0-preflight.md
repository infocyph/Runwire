# Runwire 2.2 — R0 baseline preflight evidence

**Date:** 2026-10-19
**PR:** https://github.com/infocyph/Runwire/pull/9
**Baseline published release:** `2.1.2` (published 2026-10-17)
**Baseline main commit:** `7e9bf20ad0737a187dfed77b723791b141b36378`
**Implementation branch starting commit:** `92d8ea52bf31e1adfb2f70ac981ebbf4f328558d`
**Status:** R0 IN PROGRESS — repository inspection only; no local benchmark or fresh PHPForge run yet.

## Verified source inventory

- Package: `infocyph/runwire`, requires PHP `^8.4` and `php-64bit ^8.4`; no required runtime extension or Rust package. `ext-event`, PCNTL/POSIX, Swoole/OpenSwoole, QUIC, sockets, OpenSSL and OPcache are suggested/optional capabilities where applicable.
- Development toolchain: `infocyph/phpforge: dev-main@dev`; PHPForge reusable GitHub workflow uses `@main`. These moving targets must be recorded for reproducibility.
- Native loop: `LoopFactory` prefers `EventLoop` (ext-event) and otherwise selects `SelectLoop`, whose Runwire-managed connection budget is 256.
- Hosted/runtime mode inventory: native prefork, native portable, FPM, FrankenPHP, RoadRunner, Swoole/OpenSwoole. Unsupported capabilities must fail closed.
- Benchmarks already supplied: `http1_sustained_bench.php`, `matched_release_certification.sh`, `sustained_summary.php`, `regression_compare.php`, `adaptive_promotion_matrix.php`, HTTP/3 and WebSocket fixtures.
- Tests cover request finalization, activation, persistent isolation, scheduler, HTTP1/2/3, H3 QUIC wrapper, Swoole worker lifecycle and portable native acceptance. This inventory is **not** a new passing QA result.

## Existing CI evidence (previous main commit only)

| Workflow | Outcome | Evidence |
| --- | --- | --- |
| Security & Standards | success | https://github.com/infocyph/Runwire/actions/runs/39005210596 |
| Portable Native | success | https://github.com/infocyph/Runwire/actions/runs/39005210476 |
| Benchmarks | success | https://github.com/infocyph/Runwire/actions/runs/39005210440 |
| Release Candidate | success | https://github.com/infocyph/Runwire/actions/runs/39005210431 |
| Swoole/OpenSwoole Coroutine | success | https://github.com/infocyph/Runwire/actions/runs/39005210417 |
| Source Audit | success | https://github.com/infocyph/Runwire/actions/runs/39005210388 |

All above runs correspond to `7e9bf20ad0737a187dfed77b723791b141b36378`, not the active R0 candidate. The long release-certification workflow is conditional; its prior skip cannot be reported as a pass.

## Reproducibility and correctness findings

1. `.github/workflows/release-certification.yml` still hardcodes `BASELINE_SHA=dc78bdc9d8684dcff48dac197d6cc0f21ed86043`, an older historical comparison rather than the immediate predecessor tag `2.1.2`. **Planned fix R0.09/R8.06:** resolve the published tag to its immutable commit, confirm baseline install/start and provenance, then update primary comparison while retaining any historical trend separately.
2. `matched_release_certification.sh` continues to label baseline/candidate `2.0`; `release-certification.yml` uses `2.0.x-dev` for candidate consumer installation. **Planned fix R0/R8:** correct metadata and prove Composer installed the candidate exact SHA, not an unintended version.
3. `regression_compare.php` enforces a 5% successful-RPS budget only when coefficient of variation is below 2.5%; `matched_release_certification.sh` separately requires `budget_enforced == true`. This deliberately fails on excessive variance. Neither script currently enforces p99 or RSS comparison limits. **R0.10:** select statistically defensible p99/RSS budgets after baseline measurement, without weakening throughput guard.
4. `SelectLoop::runDueTimers()` processes all due timers in a tick. **R2 investigation only:** profile timer storms, starvation and per-tick latency before altering fairness behavior.
5. The `AsyncConnection` wrapper already supports coroutine-aware receive, write, drain and close over an owned `Connection`. R5 must audit what remains blocking before introducing new API.
6. `SwooleDriver::runFactory` builds worker-local application contexts. `tests/fixtures/swoole_worker_acceptance.php` exercises both public APIs across two real workers; extend coverage rather than assume worker ownership is broken.

## Initial workload recommendation — pending measured approval

- **Primary:** native HTTP/1.1 persistent connections, small JSON response, correct response accounting, concurrency sweep.
- **Secondary:** HTTP/1 TLS, HTTP/2 multiplexing, streaming 64 KiB/1 MiB, and realistic Infbyte application routes.
- **Correctness/fault coverage:** H3/QUIC, WebSocket, TCP/Unix/UDP, hosted runtimes, cancellation/worker recycle/drain, slow clients and overload.
- **Decision metrics:** sustained successful RPM, p50/p95/p99, error/cancel counts, CPU, PHP allocation, actual process RSS, FDs, queue depth and post-soak drift.

## Remaining R0 gates (not yet executed)

- [ ] Capture reproducible local/CI environment manifest (PHP 8.4/8.5, kernel/CPU/cgroup, extensions, OPcache, Composer dependencies) and the exact tag commit SHA.
- [ ] Perform fresh full PHPForge QA, enumerate skips/warnings and validate optional-extension runtime matrix.
- [ ] Pin public/protected API inventory with failing/unsupported semantics.
- [ ] Collect profile traces and real-server warm, repeated baseline throughput/latency resource data.
- [ ] Verify hardware/load-generator parity, including correctness of representative consumer workloads.
- [ ] Reproduce the old and immediate-predecessor benchmark setup before modifying release-certification baseline.
- [ ] Set documented p99/RSS limits from measured variance and reach full R0 exit evidence.
- [ ] Update canonical R0 task checkboxes only as their complete evidence becomes available.

## Integrity

No local tests, benchmark measurements, or missing extension acceptance are claimed as passed here. This is an initial preflight artifact; follow-up evidence and exact SHA/job URLs are required before R0 is DONE and R1 begins.

## R0 certification hygiene changes (2026-10-19, PR #9)

- Verified the annotated Git tag `2.1.2` resolves to immutable commit `7e9bf20ad0737a187dfed77b723791b141b36378`. The previously pinned `dc78bdc9...` remains historical only.
- Updated release certification baseline selection, source identity validation, and `2.1.2` → `2.2` evidence labels.
- Updated consumer package path alias from `2.0.x-dev` to `2.2.x-dev`, validating copied source files against candidate checkout and expected candidate HEAD.
- This commit changes the **certification harness**; no production `src/` code changed. A full matched performance certification run on the final candidate, 5 × 180 s trials and 30-minute soak are still REQUIRED. A changed script alone is not benchmark evidence.
- Source QA on previous branch SHA `acb01ca6802c90fe16efb7c897fca5885222be92` is green:
  - Security & Standards: https://github.com/infocyph/Runwire/actions/runs/39227015800
  - Benchmarks: https://github.com/infocyph/Runwire/actions/runs/39227015117
  - Portable Native: https://github.com/infocyph/Runwire/actions/runs/39227016314
  - Swoole/OpenSwoole: https://github.com/infocyph/Runwire/actions/runs/39227015079
  - Release Candidate: https://github.com/infocyph/Runwire/actions/runs/39227015120
- Exact previous SHA security/standards job details: both PHP 8.4 and 8.5 with stable/lowest QA and static analysis passed, plus QUIC tests on both versions. Conditional release/security-report jobs skipped by design. The full release certification was skipped, **not passed**.

## R0 benchmark-comparator comparability hardening

- Extend sustained summaries to carry explicit worker count, TLS, keepalive policy, host OS/CPU, OPcache and extension-version metadata already present in raw trial evidence.
- Compare these fields across baseline and candidate; reject silent topology mismatches, missing/non-finite metrics and incorrect/missing source provenance before considering a throughput delta.
- Report observed p99 and RSS deltas without inventing acceptance thresholds. Set and enforce p99/RSS promotion budgets only after true measured R0 variance is available.
- Add Pest CLI-level regression tests covering comparable runs, mismatched environments, insufficient evidence, stable regression and noisy trials.
- **These changes are an R0 measurement-safety improvement, not certified speed gains or R0 completion.**

## R0 source-contract snapshot instrumentation

- Added `benchmarks/public_api_snapshot.php` as an explicit **developer-only** reflection tool; it does not execute during production package loading.
- Added PHP 8.4 and PHP 8.5 snapshot checks to the existing benchmark CI matrix; each result stays in the existing benchmark artifact workspace.
- Added [runtime contract inventory](r0-runtime-contracts.md) with current defaults and ownership matrix. Full-machine outputs require a successful CI run and review; R0.04 remains OPEN until verification.

## Certification-trigger decision — 2026-10-19

PR #9 was labeled `release-certification` to request **actual** conditional GitHub Actions certification on the next pull-request synchronization. R0 measurements are still **unverified**, and any pending/queued/skipped result is not a pass. Confirm that `matched-performance`, `candidate-soak`, `consumer-app` and `release-guard` all start and succeed on the exact candidate SHA before accepting their evidence. If any fails or skips, record exact failing job/artifact and keep R0 blocked until corrected.

This deliberate test invocation does not imply permission to merge, tag or publish.

## First complete actual release-certification attempt — 2026-10-19

- Exact candidate `3c94c3ef0da5575ebb7c8e61eae8fc2c67d22984`, workflow [39231526434](https://github.com/infocyph/Runwire/actions/runs/39231526434).
- **Matched performance:** five 180-second, warmed, alternating-order trials, 1 worker, keep-alive HTTP/1.1, PHP 8.5.11, ext-event 3.1.6, OPcache enabled, AMD EPYC 7763. Source checkout `2.1.2` SHA `7e9bf20...`, candidate `3c94c3ef...`; no response errors in either baseline or candidate. Baseline median successful RPM **402,324**, p99 **5.54ms**, peak RSS **130,146,304 bytes**, RPS CV **4.283%**. Candidate successful RPM **391,790.34**, p99 **5.69ms**, peak RSS **124,293,120 bytes**, RPS CV **2.002%**. Observed change **−2.618%**, **NOT QUALIFIED** because baseline RPS CV > 2.5%. The existing 5% regression guard did not certify the test; it intentionally requires `budget_enforced=true`.
- Matched performance artifact: `runwire-release-matched-performance`, artifact ID `12179875328`, ZIP SHA256 `6413e1e90ac8af607facc353b0e154970dad19e198b3ec74a1006600a36781b6`. [Workflow artifacts](https://github.com/infocyph/Runwire/actions/runs/39231526434)
- **30-minute actual soak PASSED** on one PHP 8.5.11 native HTTP worker, concurrency 32: 12,901,151/12,901,151 correct completed responses; **0 errors, 0 timeouts, 0 validation failures**; 7,167.288 successful RPS, p99 10.56 ms, peak RSS 122,028,032 bytes. Artifact `runwire-release-30m-soak` ID `12179230522`, ZIP SHA256 `6c1bb80500af42e6011a1f1d49c4019d9c5018094fb65f792521636b9b751778`. Its AMD EPYC 9V74 runner is **different** from matched benchmark runner: never compare their throughput.
- **PHPForge release guard / Security & Standards QA FAILED:** all four PHP 8.4/8.5 stable/lowest lanes reached `Tests/ReleaseBenchmarkComparatorTest.php:80` and rejected JSON integer `2` vs expected PHP float `2.0` under Pest `toBe`. 559 tests otherwise passed per representative PHP 8.4 stable QA. Fix is test-only: assert JSON's actual integer numeric representation without loosening comparator validation. Fix requires fresh exact-SHA CI.
- **Infbyte consumer FAILED dependency resolution:** `Infbyte/main` requires `infocyph/foundation:^3.0.1`, which is not published/available (Foundation releases currently 2.0 and 1.0.0). Foundation 4 and Infbyte 4 are unpublished draft PRs. This is a genuine **external publication-order blocker**, not permission to fake an alias, skip consumer tests, or edit either repository. Keep consumer certification blocked until real compatible published artifacts exist, or scope an independent native consumer smoke separately without representing it as Infbyte parity.
- R0.08 benchmark accounting and same-runner equivalence are verified for the completed matched comparison; R0.09 baseline revision is verified and its new workflow effect observed. R0.07 remains open: throughput variance is beyond qualification, and no production-representative concurrency curve/profile breakdown is yet certified.
- **QA repair:** normalize development-only API snapshot PHP 8.4/8.5 `self` reflection representation, and capture Composer lock hashes, complete locked dependencies, cgroup limits and PHP INI diff in future benchmark artifacts. These are reproducibility tools, not production performance changes.

**Gate:** Do not certify R0 or begin R1 based on the first failed release certification. The full matrix must be green on the final candidate SHA. The upstream Infbyte publication blocker remains explicitly reported instead of silently suppressed.

## Exact-sha green core QA and R1 safety preflight (2026-10-19)

- Source SHA `0414fa0153e2f58f9b174c248ed26925e4f3c10a`: [Security & Standards](https://github.com/infocyph/Runwire/actions/runs/39236538630) completed PHP 8.4 + 8.5, stable+lowest QA, both analysis jobs, PHPBench jobs and H3/QUIC jobs successfully. The automatic release/security-report jobs were skipped as conditional, **not release evidence**. [Release certification](https://github.com/infocyph/Runwire/actions/runs/39236537950) PHPForge release guard passed with ext-event.
- [Benchmarks](https://github.com/infocyph/Runwire/actions/runs/39236537936): protocol-core PHP 8.4 and 8.5 passed, API manifests and 104 locked developer dependencies captured; PHPForge lock contains `dev-main a595f8d`. The auto/adaptive benchmark job is tracked separately.
- Synthetic merge source in these benchmark artifacts is `c792f3cb1b43641f532d82522f2fb228b832725e`, NOT checkout of source branch head. The underlying source commit is exactly `0414fa0`.
- SHA-256 snapshot archives: PHP8.4 `579e84763ccf0707060ccc2792e42c0257d74819f2440b5e0b88498fa3dfc9e7`, PHP8.5 `1d1f09d7d7be0740daf00a3a26e2e6e63bc2973eb1407d1fefad1f5aa2765862`; both recorded 104 locked packages, with generated CI lock SHA-256 `bd019b2bceb243d423fb9d0906ccbe7a8afb9b1de1c8cd78367f4c65134d6238`.
- Current 3-second smoke medians: PHP8.4 419,154.9 successful RPM, CV 11.796%; PHP8.5 918,727.62 RPM, CV 3.883%, with different CPU models (AMD EPYC 7763 vs Intel Xeon 8573C). These are diagnostics only, **not comparable performance results**.
- Native prefork, portable-no-PCNTL, ext-event, Swoole/OpenSwoole and H3 extension suites have separate green workflows. Local QA was not executed because this review environment does not have a usable Runwire vendor tree; R0.03 stays open until its literal local-execution requirement is satisfied or explicitly revised.
- Current Infbyte `main` consumer remains an **external Foundation publication blocker**, no changes permitted to other repositories. The user-authorized next-step preparation is limited to regression coverage, not speculative runtime optimization; leave R0 performance exit tasks unchecked.

## CI flake diagnosis and fixture fixes (2026-10-20)

- [Security & Standards 39238884689](https://github.com/infocyph/Runwire/actions/runs/39238884689): only PHP 8.5 prefer-stable QA failed, in `Http1SustainedBenchTest.php:149` with `Invalid sustained benchmark arguments`. The test fixture used non-atomic publication of the listening port, allowing its parent to see an empty `.ready` file. The child now validates a real port and publishes readiness via atomic same-directory rename. Production benchmark argument validation is unchanged.
- [Swoole/OpenSwoole 39238884390](https://github.com/infocyph/Runwire/actions/runs/39238884390): PHP 8.4 OpenSwoole live worker-acceptance failed the exactly-two-worker traffic assertion after both workers booted. Initial 64 responses can be distributed unevenly by host reactor scheduling. Test now permits 64 bounded additional validated probes (at most 128 total), retains two distinct worker PIDs and slots, globally unique request IDs, worker-local lifecycle and exact shutdown assertions, and reports observed PIDs on failure. A single-worker runtime still fails certification.
- Both require exact-SHA CI before being considered fixed. No production `src/` code changed.
