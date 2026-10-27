# Runwire 3.0 — C4 expansive documentation delivery plan

**Scope:** Current Runwire branch `plan/runwire-independent-ecosystem-roadmaps`,
PR #9, Sphinx-native reStructuredText public documentation only.
No Foundation/Infbyte/Core/Cells edits, merge, tag or release.

## W207 — Real 2.1.2 matched H1 and native/hosted trace attribution (2026-10-27)

**U15.03 accepted within declared tracing scope:** Exact Runwire source [C4 tracing #40531507589](https://github.com/infocyph/Runwire/actions/runs/40531507589) passed PHP 8.4/8.5 native and live RoadRunner hosted independent, exact-response trace-off versus kernel strace-on scenarios with actual process CPU/RSS/FD, p99 and successful RPS. The heavy external strace probes showed native/hosted trace-on throughput retention roughly **29–48%** depending on runtime/host, increased p99 (about **1.38–2.91×**) and bounded RSS (about **1.01–1.04×**). These severe throughput losses are explicitly documented: **strace is diagnostic-only**, not free, a recommended always-on production tracer or equivalent to lighter application telemetry. Both jobs passed the predeclared bounded-trace correctness/overhead guards; R1.08 historical crosswalk accepted.

**U15.09 accepted for like-for-like 2.1.2 baseline-capable native H1 cells:** Published immutable 2.1.2 SHA 7e9bf20 and independent candidate 61b9294e passed **five alternating paired 180-second plaintext H1 trials**, 5695.347 vs 5508.078 RPS (**-3.288%**, within -5% limit); CV 0.199%/0.205% (both <2.5%), p99 5.26 vs 5.44ms and peak RSS -0.745%, in [Release Certification #40504576622](https://github.com/infocyph/Runwire/actions/runs/40504576622) (**matched job passed**, overall workflow failed *other* historical QA gates). Published 2.1.2 vs same unreleased 3.0 runtime also passed **five alternating paired 180-second verified-TLS H1 trials** under [Benchmarks #40509745518](https://github.com/infocyph/Runwire/actions/runs/40509745518), baseline/candidate both ~390.31 successful RPS, CV 0.005%/0.003%, p99 ~41.88ms, correct verification, measured RSS within budgets. The GitHub [commit diff 61b9294e..29b1a194](https://github.com/infocyph/Runwire/compare/61b9294e8c6ad1855db53b911bfe5f3ea702ceb6...29b1a1942a63d79a2c57818f78cb55ec0619965a) has **no modified production src/** and none of the frozen old/new matched runner/HTTP server implementations. These two actual comparable native H1 cells satisfy the literal U15.09 five-pair 180-second, CV/p99/RSS/RPS acceptance. New 3.0-only H2/H3/WebSocket cells had no like-for-like published 2.1.2 baseline and are qualified separately via U15.04/.07 real independent peers; this is **not** universal speedup, not cross-protocol equivalence, and not C5 release certification.

**Still OPEN:** U15.10 dedicated **mixed** 1800-second continuous native HTTP+file/TCP+autoscale+GC/OPcache/whole-process-tree soak; previous [#40523841421](https://github.com/infocyph/Runwire/actions/runs/40523841421) was cancelled before certification and must be rerun on a stable SHA. U16.02 and U16.16 externally *Read the Docs hosted* preview, navigation/search/versioning are still blocked without a real RTD project and RUNWIRE_RTD_PREVIEW_URL; GitHub strict Sphinx passing does not substitute. C5 remains queued and requires exact frozen-head signoff independently. Last Security & Standards [#40531508883](https://github.com/infocyph/Runwire/actions/runs/40531508883) caught actionable PHPForge PHPStan/PHP-CS-Fixer/Rector errors in migration-audit tool; corrected at [29b1a194](https://github.com/infocyph/Runwire/commit/29b1a1942a63d79a2c57818f78cb55ec0619965a), new exact-head CI pending.

**Canonical task counters 157/169 accepted (92.9%), 12 OPEN. C4 10/13 ACCEPTED, 3 OPEN; C5 0/9 QUEUED.** No QA relaxation, tag, merge, release or other-repository edits.

## W206 — U16.12 comprehensive 2.1.2-to-3.0 migration guide accepted (2026-10-27)

**U16.12 ACCEPTED — documentation/migration analysis, not release approval:** [Source Audit #40526482857](https://github.com/infocyph/Runwire/actions/runs/40526482857) **SUCCESS** on immutable final migration-doc source 8417ed97. The checked original published 2.1.2 property-aware PHP public API compared against the current 3.0 candidate has **32** public implementations with changed source hashes, not the earlier W97 snapshot's 26. The canonical [32-entry exact-diff migration ledger](../evidence/runwire-3.0/c4-migration-behavior-ledger.json) records for each changed symbol its operation/ownership/security migration, executable PHP regression test and explicit C5 release revalidation flag. A new strict PHP source-audit guard rejects any unknown/duplicate/missing symbol or migration without a real test and rejects unreviewed API declaration/signature changes. All six optional constructor changes and both preserved trait-composed public methods retain their independent reflected compatibility checks. [Strict Sphinx #40526482791](https://github.com/infocyph/Runwire/actions/runs/40526482791) **SUCCESS** for extensive source-aligned :doc-like public compatibility guide and before/after application code including retained server/connection method calls, opt-in out-of-reactor filesystem, H3 separate client and explicit security/process limitations. [Real independent PHP 8.4/8.5 2.1.2-vs-3.0 wire behavior #40508637661](https://github.com/infocyph/Runwire/actions/runs/40508637661) passed. All intentional available migration choices and excluded features have documentation dispositions. No claim of blanket exception or binary compatibility and **C5 U17 final frozen-source certification/maintainer signoff remains separate**.

**Still C4 OPEN:** U15.03 tracing-on/off native and real hosted; U15.09 final 5-paired 180-second protocol/workload scope beyond proven H1 plain/TLS; U15.10 final genuine mixed 1800-second whole-process-tree/GC/OPcache soak currently [running in GitHub #40523841421](https://github.com/infocyph/Runwire/actions/runs/40523841421); U16.02 and U16.16 actual Read the Docs externally hosted preview/search/version check still blocked without `RUNWIRE_RTD_PREVIEW_URL`. Do not convert GitHub Sphinx into hosted RTD evidence.

**Canonical 155/169 QA accepted (91.7%), 14 OPEN; C4 8/13 ACTIVE, 5 OPEN; C5 0/9 QUEUED.** No QA waiver, release, merge or tag.

## W205 — U15.02 true independent generator CPU-headroom qualification (2026-10-27)

**U15.02 ACCEPTED:** [C4 GitHub qualification #40524513162](https://github.com/infocyph/Runwire/actions/runs/40524513162) completed **SUCCESS all three jobs** on immutable 3.0 source af5ae924. Independent compiled C HTTP generator `wrk` ran actual exact-body/HTTP status verified 12-second warmed measurements on a separate pinned physical client core for concurrency **1/4/16/32/64**, collecting p50/p95/p99, successful requests/sec, errors/timeouts, real server whole-process-tree CPU/RSS/FD and **actual independent load generator CPU occupancy**. The no-headroom workaround was not a threshold change: PHP8.4 client CPU 15.124–15.583%, real RPS ~6,442–6,795 across tiers; PHP8.5 client CPU 17.355–17.833%, real RPS ~12,342–12,636; every tier <85% with zero invalid responses. PHP single-threaded older diagnostic was measured as generator-limited and replaced for this test, with original PHP baseline still preserved for U15.09. Real server measurements belong to the runner/cpu/environment; not universal capacity or five-pair release certification. Historical R0.07 crosswalk ACCEPTED.

**Independent U15.10 long test:** [C4 true 1800-second mixed run #40523841421](https://github.com/infocyph/Runwire/actions/runs/40523841421) was still in progress on both PHP8.4 and PHP8.5, with real continuous admitted HTTP, parallel file/TCP and worker-autoscaling cycles, process-tree FD/RSS/CPU, and GC/OPcache telemetry. No claim of pass before both jobs and artifacts complete.

**Canonical 154/169 QA-accepted (91.1%), 15 open; C4 7/13 ACTIVE (6 open), C5 0/9 QUEUED.** Other open: U15.03 tracing on/off, U15.09 full frozen 2.1.2-vs-3.0 matched protocol workload coverage, U15.10 pending soak, U16.02/.16 real hosted Read the Docs preview (missing `RUNWIRE_RTD_PREVIEW_URL`), U16.12 final full before/after behavioral migration audit. No QA waiver, merge, tag or release.

## W204 — Two more C4 qualifications accepted from independent GitHub-worker QA (2026-10-27)

**U15.07 ACCEPTED**: real independent H1, H2 HPACK/flow and H3 aioquic/QPACK client+server pressure with per-protocol CPU/RSS/p99/correctness [Security & Standards #40509728838](https://github.com/infocyph/Runwire/actions/runs/40509728838), [Benchmarks #40509745518](https://github.com/infocyph/Runwire/actions/runs/40509745518). The supported RFC6455 WebSocket real server + independent client benchmark [#40516979142](https://github.com/infocyph/Runwire/actions/runs/40516979142) passed PHP8.4/8.5, with 177,036 / 299,483 exact accepted messages, no errors or timeouts, median p99 0.612 / 0.358 ms, measured live native server CPU and RSS snapshots with raw artifacts. These are bounded protocol profiles, not sustained 180s comparative certification for new protocols (U15.09 separately open).

**U15.08 ACCEPTED**: real single-reactor off-loop binary file/TCP interleaving and simultaneous separate live autoscaling admitted HTTP traffic in [C4 GitHub-worker mixed load #40522428193](https://github.com/infocyph/Runwire/actions/runs/40522428193), PASS both PHP8.4/8.5 on source 8b182d67: 8 mixed rounds each, 384/384 successful binary file round trips and 384/384 TCP connects per version, max loop tick 12.116ms (PHP8.4) / 13.132ms (PHP8.5), peak native experiment parent FDs 62, sampled RSS 79,147,008 / 89,280,512 bytes, autoscale admission/scale-in/scale-out and zero remaining workers verified. The exact source also passed complete four-lane PHPForge QA/analyzers [Security & Standards #40522429213](https://github.com/infocyph/Runwire/actions/runs/40522429213). Does not substitute for the mandatory full 1800s mixed process-tree soak (U15.10).

**W205 pending task tests (not yet accepted):** the PHP one-core request generator genuinely saturated and failed the independent 85% headroom gate; new C-based exact-body independent generator at the GitHub-only C4 qualification retains the 85% limit for U15.02. [Full genuinely mixed 1800s qualification #40523841421](https://github.com/infocyph/Runwire/actions/runs/40523841421) is running on GitHub PHP8.4/8.5: whole-tree CPU/RSS/FD, worker GC/OPcache, continuous real HTTP and concurrent file/TCP/autoscale loops. U15.10 remains OPEN pending its completed results. U15.03 trace toggle, U15.09 final frozen workload coverage, U16.02/U16.16 externally hosted Read the Docs preview and U16.12 final 2.1.2 behavioral migration signoff remain OPEN. RTD preview not configured; cannot be asserted from Sphinx. Latest ongoing candidate remains subject to exact-head QA.

**Canonical:** 153/169 QA accepted (90.5%), 16 OPEN; **C4 6/13 ACTIVE (7 OPEN); C5 0/9 QUEUED**. No waiver, cross-repository edit, merge, tag or release.

## W202 — Rolling C4 source-verified protocol and resource acceptance (2026-10-27)

Three further tasks accepted on **immutable all-green Runwire HEAD `52c8f33d`** (full [Security & Standards #40509728838](https://github.com/infocyph/Runwire/actions/runs/40509728838), [Benchmarks #40509745518](https://github.com/infocyph/Runwire/actions/runs/40509745518), [native C4 qualification #40509728229](https://github.com/infocyph/Runwire/actions/runs/40509728229), [C3 supervisor #40509728120](https://github.com/infocyph/Runwire/actions/runs/40509728120) and strict [Sphinx #40509728133](https://github.com/infocyph/Runwire/actions/runs/40509728133)). Accepted **U15.01** genuine separate cold/warm handler/context/loop and socket syscall attribution; **U15.04** real H1 plaintext/TLS, H2 HPACK/flow pressure and H3 QUIC/QPACK/upload pressure; **U15.05** live multi-protocol slow-peer/cancel/recycle behavior. Each canonical task carries task-specific immutable evidence; R0.06/R3.09/R4.02/R4.04/R4.09 historical crosswalks now ACCEPTED.

The H1/TLS old/new 5-pair 180-second benchmark passed at 52c8f33d: verified TLS baseline/candidate both 390.31 RPS, 41.88ms p99, CV 0.005% or less. The separate plaintext H1 5-pair 180s and 1800s H1 process-tree soak also passed on earlier immutable 61b9294e with no src/ changes since. **U15.09 remains OPEN** for remaining protocol/workload matched certification; **U15.10 remains OPEN** for representative 1800-second *mixed* network/file/recycle/GC/FD soak. No short profile counts for these long-duration gates.

Current proof closes three additional U15 tasks (previous U15.06 accepted), not the whole C4. Source-only Sphinx is green; **externally hosted Read the Docs preview is still not configured** (`RUNWIRE_RTD_PREVIEW_URL` missing), so U16.02 and U16.16 remain blocked. U16.12 final frozen behavior audit, U15.02 generator headroom, U15.03 trace on/off, U15.07 complete WebSocket counterpart resource measurements, U15.08 mixed files/autoscale, U15.09/.10 long gates remain OPEN. New real WS server CPU/RSS snapshot step submitted for GitHub worker QA; do not count U15.07 before it passes.

**Canonical count 151/169 (89.3%) accepted, 18 OPEN; C4 4/13 ACTIVE; C5 0/9 QUEUED.** No new release, tag or merge.
## W201 — GitHub C4 U15.06 qualified; matched/plaintext H1 and 1800s scope recorded (2026-10-27)

[C4 GitHub qualification #40509100579](https://github.com/infocyph/Runwire/actions/runs/40509100579) passed independent PHP 8.4/8.5 real TCP sequential/overlap and fresh/pooled network comparisons with logged CPU/RSS/FD/p99/RPS; 320 fresh dials became 32 under reuse (288 hits), and both PHP lanes measured improved throughput and p99 in that bounded fixture. **U15.06 ACCEPTED, R5.08 historical crosswalk ACCEPTED.** [Release Certificate #40504576622](https://github.com/infocyph/Runwire/actions/runs/40504576622) separately passed five paired 180-second plaintext H1 comparison and a 1800-second H1 GC/OPcache/FD soak (16,909,187 correct responses, zero errors), while overall release workflow FAILED old PHPForge/consumer QA. Other U15.09 protocol/workload cells and U15.10 mixed file/recycle/autoscale 1800s stress remain OPEN. Full C4 cannot close without those, hosted RTD preview/search/version and complete migration audit. New SHA-pinned matched TLS comparison can be triggered by PR label c4-matched-tls. No generic throughput claim.

**Canonical 148/169 QA accepted (87.6%), 21 OPEN; C4 1/13, C5 0/9.** Keep exact source QA, no threshold waiver or release.
## W200 — GitHub C4 live migration and measured pooling QA (2026-10-27)

[Immutable real 2.1.2 vs 3.0 migration workflow #40508637661](https://github.com/infocyph/Runwire/actions/runs/40508637661) passed independent native HTTP status/body/header cases on PHP 8.4 and 8.5 (partial U16.12). [97c1d457](https://github.com/infocyph/Runwire/commit/97c1d457d876bbc299cbe12481de0d101f30ec8b) repairs an invalid short-duration comparator fixture while retaining strict >=180s release rules. [C4 real profiling #40505102868](https://github.com/infocyph/Runwire/actions/runs/40505102868) passed three jobs; [1663c1b7](https://github.com/infocyph/Runwire/commit/1663c1b75361ea312bc99fa67fecc0e2fa0e1e03) strengthens fresh-dial benefit proof and emits actual RPS/p99/CPU/RSS/FD metrics in GitHub job logs. New exact-head QA required before any acceptance.

Externally hosted Read the Docs [#40505123251](https://github.com/infocyph/Runwire/actions/runs/40505123251) is blocked by missing RUNWIRE_RTD_PREVIEW_URL, and 5x180s plus 1800s release-performance/other protocol qualifications remain outstanding. Full U16.12 also needs release-frozen behavioral audit. Tracker stays 147/169 accepted, 22 OPEN, C4 0/13, C5 0/9. No cross-repo work, merge/tag or release.
## W199 — Real hosted docs and current GitHub worker QA limits (2026-10-27)

The GitHub worker [hosted Read the Docs verification workflow](https://github.com/infocyph/Runwire/blob/plan/runwire-independent-ecosystem-roadmaps/.github/workflows/c4-hosted-docs.yml) is opt-in and fails closed until repository variable RUNWIRE_RTD_PREVIEW_URL is an actual HTTPS hosted preview. It verifies public index/features/search pages and the generated search index with retained GitHub artifact provenance, but an interactive Read the Docs version selector, source parity and reviewer signoff still need actual proof; do not count only this probe as complete U16.02/.16.

The [C4 diagnostic run #40504523373](https://github.com/infocyph/Runwire/actions/runs/40504523373) uncovered the affinity JSON fixture mistake repaired by [001a13a1](https://github.com/infocyph/Runwire/commit/001a13a17cac999a65669b0db4f5ac41e03d4355). The opt-in [release run #40504576622](https://github.com/infocyph/Runwire/actions/runs/40504576622) caught a missing standalone HTTP fixture helper repaired in [df75e134](https://github.com/infocyph/Runwire/commit/df75e134cf1649723e104658864ba69e0a29d987). Both require exact-new-head QA. Complete C4 remains 0/13, overall 147/169 QA-accepted, C5 queued.
## W198 — GitHub Actions-only C4 execution and hosted-documentation boundary (2026-10-27)

Runwire source edits and execution use GitHub repository operations and GitHub Actions workers exclusively. The new GitHub C4 qualification workflow produces source-pinned PHP 8.4/8.5 real network/host/file and H1/TLS syscall diagnostic artifacts; it is distinct from U15's independent performance and long-duration release gates. The matched baseline runner now rejects short trials and accidental baseline/candidate ephemeral-port aliasing.

Hosted docs requirements remain literal: repository-root .readthedocs.yaml v2 and strict Sphinx HTML already exist, and source links can resolve to the selected version/branch. U16.02 and U16.16 cannot be accepted until a real hosted Read the Docs preview, navigation, search, version labels, and complete feature coverage are verified. A GitHub Actions Sphinx artifact or source config is not an externally hosted Read the Docs preview. U16.12 remains open until the complete 2.1.2-to-3.0 API *and runtime-behavior* migration audit is signed off.

Full C4 stays 0/13; canonical 147/169 accepted and 22 OPEN; C5 remains queued. GitHub-worker-only and immutable-artifact evidence rules apply equally to documentation.
## W196 — Runwire 3.0 exact-head QA and C4 tracker reconciliation (2026-10-27)

**Verified immutable source:** [`8565d415`](https://github.com/infocyph/Runwire/commit/8565d4157f6ab62ad5bf8b400a530eea6296e6e8). All **ten** expected Runwire workflow checks reached **SUCCESS** on that exact commit, including [Security & Standards #40501019287](https://github.com/infocyph/Runwire/actions/runs/40501019287), [Benchmarks #40501018864](https://github.com/infocyph/Runwire/actions/runs/40501018864), [HTTP/2 independent #40501018745](https://github.com/infocyph/Runwire/actions/runs/40501018745), [Real Hosted Runtime Acceptance #40501018725](https://github.com/infocyph/Runwire/actions/runs/40501018725), [Portable Native #40501018912](https://github.com/infocyph/Runwire/actions/runs/40501018912), [Swoole/OpenSwoole #40501018769](https://github.com/infocyph/Runwire/actions/runs/40501018769), [C3 native PHP 8.4/8.5 #40501018715](https://github.com/infocyph/Runwire/actions/runs/40501018715), [Release Candidate #40501018741](https://github.com/infocyph/Runwire/actions/runs/40501018741), [Source Audit #40501018770](https://github.com/infocyph/Runwire/actions/runs/40501018770), and [strict Sphinx documentation #40501018773](https://github.com/infocyph/Runwire/actions/runs/40501018773). The earlier W194/W195 *QA pending* notes are archived checkpoints, superseded by this successful exact-source matrix.

**Canonical checklist recount:** **147/169 QA-accepted (87.0%), 22 OPEN**; closure groups **C1 11/11 DONE**, **C2 5/5 DONE**, **C3 9/9 DONE**, **C4 0/13 ACTIVE**, **C5 0/9 QUEUED**. The extensive 20-chapter source-aligned Sphinx manual and local strict HTML documentation QA are complete. They do **not** prove C4's ten independent U15 matched baseline/saturation/1800-second benchmark tasks, U16.02/.16 external hosted Read the Docs preview/search/version evidence, or U16.12 final migration behavior signoff. No acceptance checkboxes have been promoted for documentation alone.

**Evidence boundary:** this tracker-only synchronization commit changes the exact branch head; its CI must be evaluated on its own SHA. Do not transfer the green state of `8565d415` to a later SHA until that SHA has actually passed. No release, merge, tag, unrelated-repository edit, or security/QA waiver.

## W193 — Strict Sphinx QA accepted for 20-chapter source documentation (2026-10-27)

The completed [Sphinx #40499541538](https://github.com/infocyph/Runwire/actions/runs/40499541538)
workflow **PASSED** the warnings-as-errors native RST HTML build at
immutable [`b6d9bcf4`](https://github.com/infocyph/Runwire/commit/b6d9bcf4eb3dc3bd08b62c5e4278ed45b4a3147a).
The validated public documentation includes 20 indexed source-aligned
manual chapters, source-generated API signatures, first-use examples,
native H1/H2/H3/WebSocket/TCP/Unix/UDP failure and lifecycle guides,
operation and incident exercises, reviewed 2.1.2 migration matrix,
and explicit C4 performance lab describing the genuine five-pair
180-second baseline tool. Read the Docs v2 config is present.

**Accepted deliverable:** the *local/CI Sphinx source documentation*,
not C4's ten U15 benchmark tasks, the external hosted RTD
preview/version/search check, or final frozen 2.1.2 behavior migration
review. Those acceptance tasks require independent evidence.
Canonical remains **147/169 accepted (87.0%), 22 open;
C4 0/13; C5 queued** until all U15 and U16 gates pass.
No merge/tag/release, QA bypass or cross-repository edit.

## W192 — C4 matched-pair handbook strict Sphinx follow-up (2026-10-27; exact-head QA pending)

The [Sphinx #40499360022](https://github.com/infocyph/Runwire/actions/runs/40499360022)
check on W191 found a single under-length RST subsection underline in the
new documented matched 180-second release benchmark instructions.
Correct the title underline without changing any measured protocol,
release baseline or acceptance gate. Preserve C4 **0/13**, overall
**147/169**, with independent hosted Read the Docs and U15 certification
still outstanding. The next exact-source Sphinx and PHPForge CI must verify
this correction.

## W191 — Strict Sphinx handbook proof and executable performance baseline (2026-10-27)

**[Sphinx #40499078612](https://github.com/infocyph/Runwire/actions/runs/40499078612)
PASSED** strict native RST warnings-as-errors and generated source-linked
API navigation on the 20-guide handbook at immutable `e1518c05b2`.
The performance laboratory now documents the actual built-in
`benchmarks/matched_release_certification.sh` 2.1.2/3.0
five-pair 180-second comparator, its separate worktrees, pinned
baseline SHA, dependency installation, CPU affinity, OPcache,
success and variance gates, immutable candidate and output artifacts.
This is a **documented executable workflow**, not an executed 180s
performance release certification. Full C4 U15, hosted RTD
preview/version/search and exhaustive migration review remain open.
**No task acceptance counted: 147/169, C4 0/13**.

## W190 — Documentation depth and failure-atlas expansion (2026-10-27; QA pending)

Added a further **five fully indexed public RST chapters** (~35.8k source
characters) beyond the W189 fifteen-chapter handbook. The additions provide
a literal first-use learning path, independent H1/H2/H3/WS/TCP/Unix/UDP
admission and shutdown troubleshooting, native worker incidents/reload/rollback
exercises, a published 2.1.2-to-unreleased 3.0 *source-audited* migration
review matrix and ten individually enumerated C4 U15 performance protocols.
They link to real, existing Runwire fixtures and to the same source-linked
generated API. The public root README now links directly to the manual.

**QA:** The first new-source Sphinx run #40498818005 found only short
top-level underline headings on these five chapters; the next corrective
commit pads every heading to the title width and requires fresh strict
Sphinx + PHPForge QA. No source assertions, native protocol coverage or
performance acceptance is waived. **C4 stays 0/13**, canonical **147/169,
22 OPEN**. Hosted RTD navigation/search/versioning is not verified by a
local HTML artifact; U16.02/12/16 remain open.

## Objective

Provide an extensive, task-oriented Runwire 3.0 developer and operations manual
instead of a brief README or incomplete feature list. Ensure every user can
understand what is implemented, the host requirements, supported APIs, how the
system behaves on errors and shutdown, and exactly how to validate a claim.
Do not mark C4's ten U15 performance tasks complete from documentation.

## Version and claim boundary

- Published stable remains Runwire 2.1.2; 3.0 is an unreleased branch.
- C3 was accepted 9/9 and immutable source `65c2da7e` passed all ten tracked CI workflows.
- Canonical checklist begins this documentation tranche at **147/169,
  22 OPEN, C4 0/13**. U16.02, U16.12 and U16.16 remain open.
- WebTransport excluded; native kernel filesystem AIO unqualified; HTTPS
  proxy + inner HTTPS TLS nesting unsupported. H3/QUIC requires native capability.
- No premature U16 acceptance from static pages: hosted RTD preview, final
  migration behavioral audit and feature-coverage checks are independent.

## Public manual information architecture

| Document | Reader and required coverage |
| --- | --- |
| handbook-3.0.rst | Overview, audience, capabilities, execution models |
| quickstart-lab-3.0.rst | Self-contained developer learning path through real acceptance fixtures |
| protocol-failure-atlas-3.0.rst | H1/H2/H3/WS/TCP/Unix/UDP failure, stream admission and drain |
| operations-exercises-3.0.rst | Native signals, graceful stop, recovery, incident and rollback rehearsals |
| compatibility-audit-3.0.rst | Reviewed 2.1.2 declaration delta and behavioral migration checklist |
| performance-lab-3.0.rst | All ten U15 experimental gates, accepted evidence and exclusions |
| runtime-lifecycle-3.0.rst | Resource ownership, stop/drain/fork state machines |
| configuration-reference-3.0.rst | Runtime options, server limits, worker/scale policy |
| inbound-handbook-3.0.rst | H1/H2/H3/WS/TCP/Unix/UDP server semantics |
| outbound-handbook-3.0.rst | DNS/dial/TLS, HTTP clients, pools, proxy and relay |
| concurrency-handbook-3.0.rst | Loops, Fiber scopes, task cancellation and synchronization |
| supervision-handbook-3.0.rst | Native lifecycle, restart/reload/autoscale, recovery |
| filesystem-handbook-3.0.rst | Off-reactor file workers, streaming, quotas, security |
| security-handbook-3.0.rst | Threat model, SSL/ALPN, SSRF, auth and trust boundaries |
| production-operations-3.0.rst | Deployment, readiness, rollback and incidents |
| observability-handbook-3.0.rst | Metrics, tracing, errors, process-tree signals |
| performance-qa-handbook-3.0.rst | Matched benchmarks, QA provenance, 1800s soak |
| migration-playbook-3.0.rst | Published 2.1.2 → unreleased 3.0 before/after and API audit |
| troubleshooting-handbook-3.0.rst | Symptom-driven diagnosis and reproducible probes |
| documentation-maintenance-3.0.rst | Documentation CI, source sync, code example rules |

Existing focused 3.0 transport/protocol/coroutine/file/host/API/migration
references remain and are linked under the root index. Historical 2.x guides
remain separate and clearly labeled.

## Completeness criteria

1. Source-linked public API and meaningful navigation, no toctree orphans.
2. Actual API signatures validated against the current source; no guessed calls.
3. Every large feature described with host/extension requirements, ownership,
   admission, cancellation, drain, failure behavior and security conditions.
4. Source-backed runnable CLI/Pest fixture references must exist; PHP examples
   pass existing documentation snippet syntax checks.
5. Invalid configurations, backpressure and shutdown troubleshooting documented.
6. Code examples explicitly distinguish stable 2.1.2 and dev 3.0.
7. Full 2.1.2 migration audit references additive types, trait-composed methods,
   optional constructor extensions and remaining behavioral review; do not
   invent breaking changes.
8. Native/portable/host differences and release QA distinctions stated clearly.
9. Strict `sphinx-build -n -W --keep-going -b html docs build/sphinx-html`,
   existing snippet/feature/link checks and full repository CI all green.
10. Hosted Read the Docs preview, search, version labels and site availability
    confirmed separately before U16.02/U16.16 acceptance.

## Planned evidence and closure

* **Documentation source deliverable:** twenty new fully indexed public RST
  guides (~138k characters), upgraded overview/start/architecture/status pages,
  and explicit Read the Docs v2 config.
* **CI:** documentation workflow, PHPForge four Pest lanes, two analyzers,
  source audit, portable/hosted and independent protocol suites on immutable SHA.
* **External gate:** actual hosted RTD staging URL, build logs, search/version
  selector and navigation captured by maintainer. A local build is insufficient.
* **Migration gate:** compare fresh 2.1.2 manifest and frozen 3.0 public
  declarations, review behavioral differences and record maintainers' decisions.
* **Tracker:** keep U16.02/12/16 and C4 0/13 pending until the appropriate
  evidence passes. C5 remains queued.
