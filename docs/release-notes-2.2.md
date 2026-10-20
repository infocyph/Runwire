# Runwire 2.2 — draft release notes (not published)

**Status:** DEVELOPMENT CANDIDATE — release certification is incomplete. No merge, tag, version publication, or verified performance gain is claimed here.

**Comparison:** Immediate preceding published tag [2.1.2](https://github.com/infocyph/Runwire/releases/tag/2.1.2) at immutable commit `7e9bf20ad0737a187dfed77b723791b141b36378`. [Current development comparison](https://github.com/infocyph/Runwire/compare/2.1.2...plan/runwire-independent-ecosystem-roadmaps).

## Runtime and transport refinements

- **Hosted request normalization:** Inlined CGI header-name translation for `HTTP_*`, `CONTENT_TYPE` and `CONTENT_LENGTH` in `HostRequestFactory`, avoiding an extra per-server-entry helper dispatch while maintaining scalar validation and normalized `HeaderField` enforcement. Expanded host scalar-header regression tests.
- **Portable event-loop fairness:** `SelectLoop` now caps due-timer work at 256 entries per tick, preserving outstanding timers for subsequent ticks so ready network watchers are not starved by a timer storm. Added 600-timer plus ready-socket tests. The ext-event backend is unchanged.
- **ByteQueue work batching:** Shared worker byte-budget release and chunk compaction now happen once per `ByteQueue::read()` batch rather than per consumed chunk. Added tests for 300 chunks, partial offsets, interleaved discard, shared quota and queue reuse. This does not remove any buffer ceiling.
- **Compatibility:** No new public or protected runtime API contract is introduced by those changes. PHP `^8.4`, 64-bit, framework-agnostic Composer install, optional ext-event, PCNTL/POSIX, TLS, QUIC and host integrations remain as before.

## Benchmarking, QA and reproducibility

- Pinned primary release comparison to the **published 2.1.2 commit**, not the older pre-consolidation revision. Added exact-source candidate validation in CI and path-repository consumer installation.
- Matched comparison now checks consistent PHP/host, workers, concurrency, TLS, extensions, OPcache and time. Retains the 5% successful-RPM regression budget only when RPS CV is below 2.5%, and independently enforces p99 and RSS safety ceilings.
- Added PHP8.4/8.5 reflected API manifest, exact locked development dependency record, worker/cgroup/OPcache environment inventory, phase-scoped hosted request profiler, and comparator regression tests.
- Hardened HTTP/1 fixture's readiness publication and the live Swoole/OpenSwoole two-worker acceptance against scheduling variance while retaining strict response correctness and worker-identity validation.
- The standard PHPForge, native portable, Swoole family, protocol benchmark and audit workflows have passed at prior exact commit checkpoints. The **final commit** still requires full source QA and optional-host/certification verification.

## Deliberately unchanged or deferred

- No dependency or runtime integration with independently developed Rust Runwire-Core or Runwire-Cells.
- No new nonblocking TCP/TLS dialer, DNS client, HTTP client or implicit asynchronous PHP database I/O. Existing `AsyncConnection` works on an already established connection and retains bounded cancellation and callback ownership.
- No default AUTO scheduling promotion, unverified HTTP2/HTTP3 codec rewrite, new required extension or API compatibility shim.

## Not yet certified

- No defensible **successful-RPM improvement** over 2.1.2 is established: previous actual five ×180s paired benchmark was variance-inconclusive, because baseline RPS CV exceeded the established 2.5% gate.
- One previous 30-minute native HTTP soak passed with 12,901,151 valid responses and zero errors, but that is not a soak of the final candidate.
- Final H1/H2/H3/tail/RSS, multi-protocol drain and fault tests, PHP8.4/8.5 full QA, five ×180s matched performance, 30-minute soak and consumer bridge certification remain required.
- Infbyte/main consumer testing is externally blocked by an unavailable declared Foundation `^3.0.1` dependency. Do not publish a success claim or alter external repositories to bypass this.

**Release authority:** Only the maintainer may merge, tag or publish once the [handoff checklist](plans/evidence/runwire-2.2/release-handoff.md) has all required gates verified.
