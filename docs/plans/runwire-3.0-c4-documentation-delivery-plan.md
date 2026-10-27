# Runwire 3.0 — C4 expansive documentation delivery plan

**Scope:** Current Runwire branch `plan/runwire-independent-ecosystem-roadmaps`,
PR #9, Sphinx-native reStructuredText public documentation only.
No Foundation/Infbyte/Core/Cells edits, merge, tag or release.

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

* **Documentation source deliverable:** fifteen new fully indexed public RST
  guides (~100k characters), upgraded overview/start/architecture/status pages,
  and explicit Read the Docs v2 config.
* **CI:** documentation workflow, PHPForge four Pest lanes, two analyzers,
  source audit, portable/hosted and independent protocol suites on immutable SHA.
* **External gate:** actual hosted RTD staging URL, build logs, search/version
  selector and navigation captured by maintainer. A local build is insufficient.
* **Migration gate:** compare fresh 2.1.2 manifest and frozen 3.0 public
  declarations, review behavioral differences and record maintainers' decisions.
* **Tracker:** keep U16.02/12/16 and C4 0/13 pending until the appropriate
  evidence passes. C5 remains queued.
