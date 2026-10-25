# U14.08 — security, PHPForge and dependency audit closure

**Accepted task:** U14.08 on immutable W129 source `af7b83c00348544bf2ddc67d65c7594afd839c2e`, as of 2026-10-25.

## Required evidence

- [Security & Standards #40180033609](https://github.com/infocyph/Runwire/actions/runs/40180033609): successful workflow; PHPForge Pest/QA PHP 8.4 and PHP 8.5 × stable/lowest, PHPStan analysis on both versions, and optional native QUIC protocol test lanes on both versions succeeded.
- [Benchmarks #40180033273](https://github.com/infocyph/Runwire/actions/runs/40180033273): successful protocol-core PHP 8.4/8.5 jobs; their **Audit exact locked dependencies for published security advisories** steps both concluded success.
- No unsuppressed actionable security/advisory/analyzer failure remains in those exact-head checks.

## Important exclusions

This acceptance does **not** certify all of the future 3.0 branch changes, satisfy independent WebTransport fuzz/stream cancellation U14.04, declare unfinished network protocols supported, or satisfy U17.02's **frozen release-source** PHPForge/security re-run. The canonical tracker records 123/175, with 52 unaccepted tasks; the final release is blocked until they are accepted and the opt-in immutable certification succeeds.
