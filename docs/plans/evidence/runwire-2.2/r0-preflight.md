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
