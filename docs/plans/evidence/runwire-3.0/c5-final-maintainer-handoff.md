# Runwire 3.0.0 — C5 final maintainer release handoff (GitHub-only, unpublished)

**Prepared:** 2026-10-28. **Scope:** draft [PR #9](https://github.com/infocyph/Runwire/pull/9) branch `plan/runwire-independent-ecosystem-roadmaps`. **Release decision:** READY FOR MAINTAINER RELEASE REVIEW on successful exact-final-head CI; NOT APPROVED TO MERGE, TAG, RELEASE, DEPLOY OR PUBLISH. This handoff is the accepted U17.06–09 technical closure record, not a release announcement.

## Immutable code, baseline and documentation-only handoff

- The last implementation/benchmark change was full SHA [`13c3bdb09c9460b2b5feebea38e7d78ff14004f4`](https://github.com/infocyph/Runwire/commit/13c3bdb09c9460b2b5feebea38e7d78ff14004f4); code and benchmark sources tested below remain unchanged by the subsequent handoff/tracker commit.
- Published comparison reference is immutable 2.1.2 source `7e9bf20ad0737a187dfed77b723791b141b36378`; no 3.0 baseline substitution. Comparisons are paired, same environment and workload, and profiles must not be cross-compared to older generators.
- Canonical task inventory **167 IDs, 167 checked accepted, 0 OPEN**; U16.02 and U16.16 external hosted-site tasks intentionally retired from GitHub-only scope, **not** accepted. Five disjoint closure groups: C1 11/11, C2 5/5, C3 9/9, C4 11/11, C5 9/9.
- The handoff commit changes only this internal documentation and the plan, roadmap, risk and engineering audit. Its new SHA requires its own source audit/QA and release certification before presentation to the maintainer as fully GREEN; all production behavior is frozen from `13c3bdb09c9460b2b5feebea38e7d78ff14004f4`.

## Immutable accepted evidence

| Gate | Exact-source GitHub Actions and retained evidence | Disposition |
| --- | --- | --- |
| PHPForge/security/QA/fuzz/native H3/QUIC | [#40653816760](https://github.com/infocyph/Runwire/actions/runs/40653816760), both PHP8.4/8.5 stable/lowest + PHPStan/Psalm, native H3/QUIC, Composer | SUCCESS |
| Independent HTTP/2 reference, hosted, portable and coroutine | [#40653816473](https://github.com/infocyph/Runwire/actions/runs/40653816473), [#40653816721](https://github.com/infocyph/Runwire/actions/runs/40653816721), [#40653816548](https://github.com/infocyph/Runwire/actions/runs/40653816548), [#40653816543](https://github.com/infocyph/Runwire/actions/runs/40653816543) | SUCCESS |
| Source audit, migration, documented API/feature coverage | [#40653816490](https://github.com/infocyph/Runwire/actions/runs/40653816490), [#40653816514](https://github.com/infocyph/Runwire/actions/runs/40653816514), [41-page native Sphinx #40653816357](https://github.com/infocyph/Runwire/actions/runs/40653816357) | SUCCESS |
| Matched real TLS/ALPN HTTP2 | [#40653954379](https://github.com/infocyph/Runwire/actions/runs/40653954379), [artifact #12803260243](https://github.com/infocyph/Runwire/actions/runs/40653954379/artifacts/12803260243); five alternating 180s pairs, 30s warmup, baseline/candidate successful median RPS **4366.56/6089.28**, CV **0.395%/0.671%**, p99 **8.85/8.55ms**, real independent generator CPU **40–56%** | ALL CHECKS PASS |
| Matched real RFC6455 WebSocket | [#40653980967](https://github.com/infocyph/Runwire/actions/runs/40653980967), PHP8.4 [#12802890153](https://github.com/infocyph/Runwire/actions/runs/40653980967/artifacts/12802890153) and PHP8.5 [#12802491182](https://github.com/infocyph/Runwire/actions/runs/40653980967/artifacts/12802491182); five pairs ×180s both PHP; PHP8.5 candidate **-4.577%** RPS against unchanged -5% floor | ALL CHECKS PASS |
| Continuous 1800s mixed H1, file/TCP, autoscale and resource tree | [#40653969016](https://github.com/infocyph/Runwire/actions/runs/40653969016), PHP8.4 [#12802725755](https://github.com/infocyph/Runwire/actions/runs/40653969016/artifacts/12802725755), PHP8.5 [#12802133794](https://github.com/infocyph/Runwire/actions/runs/40653969016/artifacts/12802133794); successful requests **14,835,021 / 15,666,623**, **0 errors**, 572 file/TCP and 89 autoscale cycles each, GC/OPcache/FD/RSS/children checks PASS | BOTH PHP VERSIONS SUCCESS |
| Full H1 release certification and independent Composer packages | [#40653992566](https://github.com/infocyph/Runwire/actions/runs/40653992566), [matched artifact #12803190025](https://github.com/infocyph/Runwire/actions/runs/40653992566/artifacts/12803190025), [1800s H1 artifact #12802861509](https://github.com/infocyph/Runwire/actions/runs/40653992566/artifacts/12802861509), [decision #12802785865](https://github.com/infocyph/Runwire/actions/runs/40653992566/artifacts/12802785865); six required jobs SUCCESS | CHILD GATES PASS; previous aggregate *correctly* rejected 4 OPEN ledger tasks |
| Routine source QA and release candidate | [#40653816320](https://github.com/infocyph/Runwire/actions/runs/40653816320), [#40653816490](https://github.com/infocyph/Runwire/actions/runs/40653816490), [#40653816760](https://github.com/infocyph/Runwire/actions/runs/40653816760) and other required workflows on code source `13c3bdb09c9460b2b5feebea38e7d78ff14004f4` | SUCCESS |

## Risk decisions and migration/distribution boundaries

The [formal W226 C5 risk register](c5-risk-review.md) reviews security/fuzz, native H3/QUIC, host-owned lifecycle, process/GC/OPcache, published baseline comparisons, migration and protocol exclusions. There is **no remaining evidence-backed release-blocking finding** within the approved GitHub-worker scope. Verified technical correctness does not imply an absence of all possible defects. The key measured residual is the PHP8.5 WebSocket -4.577% throughput margin near the -5% floor; monitor, do not suppress. **No broad unqualified universal speedup claim.**

Breaking 2.x→3.0 API changes require the documented migration guides; the 3.0 feature list must accurately describe support on 64-bit PHP 8.4+, optional native extensions, nonblocking event-loop semantics and host-owned execution. WebTransport is entirely excluded because native RFC9221 QUIC DATAGRAM support was not available; HTTPS-proxy nested TLS intentionally fails closed; filesystem offloads through bounded worker processes and does not promise kernel-native AIO or crash-atomic replace; external Read the Docs hosting is outside GitHub-only acceptance. No silent transport fallback.

## Final release-readiness control (nonpublishing)

1. Verify the exact final handoff commit contains the same `src/`, benchmark, test, workflow and Composer content as `13c3bdb09c9460b2b5feebea38e7d78ff14004f4`; no behavior changes in the signoff commit.
2. Verify GitHub Source Audit, Sphinx, portable/hosted/native, PHPForge QA/analysis on that final exact-head commit. Avoid treating previous-source green jobs as proof of a changed implementation.
3. Re-run opt-in **Release Certification** for that exact commit; verify all six children SUCCESS, `open_tasks=0`, `certification_ready=true` and `release_tag_created=false`, `package_published=false` in the retained final decision artifact.
4. Keep PR #9 **draft**, unmerged, untagged, unpublished; handoff authorizes review readiness only. Any actual release step needs a new explicit maintainer instruction. No merge, tag, GitHub release, composer publish, Packagist publication, deployment, version bump or auto-merge.

**U17.09 acceptance:** final documentation and technical evidence are prepared, with non-publishing status and separate publication authority explicit. If final exact-head QA or release decision is RED, **hold release readiness** and correct the actual failure; do not relabel the failure as accepted or alter performance limits.
