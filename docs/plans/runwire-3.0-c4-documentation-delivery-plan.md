# Runwire 3.0 — C4 expansive documentation delivery plan

**Scope:** Current Runwire branch `plan/runwire-independent-ecosystem-roadmaps`,
PR #9, Sphinx-native reStructuredText public documentation only.
No Foundation/Infbyte/Core/Cells edits, merge, tag or release.

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
