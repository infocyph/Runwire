# Runwire next-release candidate — security, compatibility and release handoff

**Status:** Engineering/CI evidence prepared. **NOT release-authorized.** This is not an independent security sign-off.

**Published comparison:** tag [3.0.1](https://github.com/infocyph/Runwire/releases/tag/3.0.1) (2026-11-10) to [PR #17](https://github.com/infocyph/Runwire/pull/17), branch `audit/engineering-principles-13-structural-window`. The 18 changed `src/` paths are modifications to already published symbols. No `src/` PHP file deleted. Do not compare the performance numbers to 3.0.1: the certified performance **reference** is the previously published **3.0 baseline** `054737bc6a8d4025a8d05fabb8ad390f66ee47ae`.

## 1. P0 — Independent FLOW-02 security assessment (UNSIGNED)

The following conditions need review by a **different qualified reviewer** from the implementer; source and green CI alone do not grant independent approval.

| Boundary / source | Verified design and existing coverage | Assumption / unresolved reviewer decision |
| --- | --- | --- |
| `src/Network/Dialer.php`, `DialOptions`, `OutboundAddressPolicy` | Numeric TCP candidate pre-dial validation at `Dialer::assertAddressPolicy()`; kernel-observed connected peer validated before TLS/handoff when `addressPolicy` provided. IPv4-mapped/IPv6/private/multicast and rebinding-negative cases in `tests/OutboundAddressPolicyTest.php`. | The policy is **optional** for caller-authorized connections. Reviewer must establish that every entry path accepting untrusted URL or DNS input injects the policy. A low-level dialer is **not** a general SSRF sandbox by default. |
| `src/Interop/Psr/PsrMessageBridge.php::outboundRequest()` | Fail-closed exact URI approval (scheme/authority/port/path/query, including userinfo); `tests/PsrMessageBridgeTest.php` tests missing/mismatch/private-substitution. Approved URI is the only value used to populate `ClientRequest`. | This is **not DNS authorization**: reviewer must check trust provenance of the provided approved string and use of network policy downstream. App may not use `$request->getUri()` to create its own permission. |
| `src/Executor/ProcessExecutor.php`, `src/Executor/Internal/ExecutorIpcTrait.php`, `runtime/executor-worker.php` | 32 random key bytes rendered as hex, HMAC-SHA256 authenticated framing over private subprocess pipes; worker clears IPC key environment. `guardFilePath()` rejects NUL/wrapper syntax and uses canonical restricted-root, symlink and file-kind checks. Operation size limits and tests are in `tests/ProcessExecutorTest.php`. | Four **function-scoped** Psalm taint escape categories (`file`, `ssrf`, `url_component`, `url_path`) are allowlisted with exact sanitizer SHA in `composer.json`; each was required by default Psalm URL-capable filesystem sink modeling. File-system restricted-root is **not race-free** against concurrent adversarial symlink/rename operations. Unrestricted-root mode intentionally inherits parent file authority. Decide whether trust model is sufficient or sandbox redesign is mandatory. |
| `src/WebSocket/Internal/WebSocketClientHandshake.php`, `src/WebSocket/WebSocketUpgrade.php` | Narrow `WeakHash` exceptions for RFC 6455 `Sec-WebSocket-Accept` wire-required SHA-1 only. | No replacing SHA-1 with an incompatible digest or generalizing the exception to credential hashes. |
| `.gitattributes`, worker packaging, `.github/workflows/release-candidate.yml` | `/tools export-ignore` and required `runtime/executor-worker.php` packaged and tested in real installed Composer consumer runs on PHP 8.4/8.5/8.6. | Reviewer confirms no arbitrary executable PHP from untrusted IPC and package layout is accessible from installed vendor path. |

**Exact source certification (not a security sign-off):**
- Source SHA `4c7704a20c15ea0364a5d6e82757f19b3f146a6a`: eight required workflow families green, including [Security & Standards](https://github.com/infocyph/Runwire/actions/runs/44983585423) with Psalm/PHPStan default PHPForge config and all six PHP 8.4–8.6 QA lanes; Source Audit, Release Candidate, Sphinx, H2 reference, hosted/portable native and independent QUIC all passed.
- [Release Certification 44984218524](https://github.com/infocyph/Runwire/actions/runs/44984218524): 5 matched 180-second trials, median successful H1 RPS **8,864.439 → 8,952.658 (+0.995%)** relative to the **3.0 performance baseline**, median p95 **3.15→3.06 ms**, p99 **3.28→3.27 ms**, peak RSS **132,976,640→134,225,920 bytes (+0.939%)**. 30-minute H1 soak **19,848,422 successful / 19,848,422 requests**, zero errors/timeouts/validation failures. Do not generalize this to H2/H3 or to a comparison with published 3.0.1.
- Tracking-only exact HEAD `7ac413fa4d68df7a2b53272bd1b4a6b55a0c58ab`: eight required workflows green; 18 historical GitHub automated review threads replied to and resolved. Review this current branch HEAD separately following documentation changes.

**Independent reviewer disposition (must be filled by the reviewer):**
- Reviewer identity: **PENDING**
- Reviewed commit SHA, threat model and affected callers: **PENDING**
- Candidate/peer and worker sandbox boundary accepted, restricted or rejected: **PENDING**
- Exceptions/rationale and any required fixes: **PENDING**
- Explicit FLOW-02 approval (with PR review link): **PENDING**

## 2. P0 — Public-contract and version decision (UNAPPROVED)

**Proven published behavior:** `PsrMessageBridge::outboundRequest()` in **tag 3.0.1** accepts a bodyless PSR request with four or fewer positional arguments and no `approvedUri`. The PR adds a trailing optional fifth `approvedUri` parameter but **throws `InvalidArgumentException` at runtime when omitted or mismatched**. Existing callers are affected despite a source-compatible signature.

**Security choice already implemented:** keep the adapter fail-closed and require a configured approved URI. Rolling back the guard just to make analysis green or preserve insecure invocation is not an acceptable fix. Preserve direct `ClientRequest` and `Dialer` authority contracts and explain outbound network policy separately.

**Version decision still required:** latest published tag is `3.0.1`. One viable policy is a clearly disclosed **3.0.2 security-fix patch exception** (only if project maintainers explicitly accept the behavior tightening and notify consumers). Under strict backward-compatible SemVer patch rules, choose a versioning/release policy that acknowledges the break (e.g. new major) rather than claiming unconditional patch BC. Do **not** silently label the tag `3.0.2` or `4.0.0` before approval.

**Documented consumer migration:** `docs/migration-playbook-3.0.rst`, section `Post-3.0.1 security hardening: PSR outbound URI approval`; opt-in outbound handbook example at `docs/outbound-handbook-3.0.rst`. Update release notes with before/after snippet and migration impact, not just a phrase like "security fixes."

**Maintainer decision (must be filled explicitly):**
- Chosen release version and compatibility exception policy: **PENDING**
- Confirmation that the fail-closed adapter behavior is intended: **PENDING**
- Approval to publish migration notice: **PENDING**
- Maintainer identity, date and PR review link: **PENDING**

## 3. Release-note draft (3.0.1 → next version; NOT YET PUBLISHED)

### Security and compatibility
- Outbound PSR-7 metadata adaptation now requires a trusted, exact `approvedUri`. Earlier 3.0.1 usage without it throws at runtime. Migrate with a trusted configured URI, never by copying the untrusted request's URI. Preserve candidate-IP and connected-peer verification for untrusted DNS.
- Authenticated worker filesystem operations have explicit bounded, wrapper-rejecting and optional root-constrained path validation; restricted-root checks are not a race-free sandbox. Four precisely scoped default-Psalm sink escapes remain documented. RFC 6455 SHA-1 remains required solely for WebSocket interoperability.

### Throughput and runtime
- PSR-15 adapter prebuilds its immutable middleware chain once rather than reconstructing up to 32 layers per request.
- HTTP/1 constructs its WebSocket upgrade owner only on the upgrade path; HTTP/3 reuses validated origin and preflight codec, and the native QUIC worker avoids unnecessary poll-array copies.
- QPACK skips blocked-stream retry scans without new inserts; metrics/lifecycle counter initialization avoids mapped-array temporaries; stream writer reuses its scoped error-handler closure.
- Certified **plaintext HTTP/1.1** matched 3.0 baseline improvement **+0.995% RPS** with **+0.939%** peak RSS; H2/H3 gains and file-count reduction **not established**.

### Packaging, QA and operational handoff
- Runtime worker relocated from development-only `tools/` to package-included `runtime/`; release archives exclude `tools/` and installed worker contract is verified.
- Default PHPForge Psalm/PHPStan/QA verified on PHP 8.4–8.6, optional native H3 QUIC verified on all three; docs, hosted runtime, portable and independent H2 tests pass.
- Public symbols from 3.0.1 retained; no artificial class/file consolidation in a patch. Avoid implying all behavior is unchanged.

## 4. Authorized execution order (no automatic publication)

1. [x] Exact **source** PHP 8.4–8.6 CI and [certification](https://github.com/infocyph/Runwire/actions/runs/44984218524) completed. Historical bot reviews closed.
2. [ ] B18 documentation/current exact-head CI and manifest/installed package gates green. Never reuse a passing *older* SHA after code changes.
3. [ ] Independent qualified reviewer documents FLOW-02 sign-off on the latest source and residual worker trust boundary.
4. [ ] Maintainer documents chosen release version and the intentional 3.0.1 → next version API migration exception/semantics; approves notes.
5. [ ] Maintainer authorizes PR #17 leaving draft; obtain actual non-bot PR approval and verify checks against intended merge commit. Do not merge automatically.
6. [ ] After authorized merge, verify CI on `main` and capture exact release commit SHA. Only then create chosen release tag. GitHub workflow `.github/workflows/security-standards.yml` runs reusable `infocyph/phpforge/.github/workflows/release.yml@main` for matching version-tag pushes; monitor and verify artifacts rather than assuming publication success.
7. [ ] Verify GitHub release/package distribution/Composer consumer install, Sphinx docs and rollback notes; record final tag SHA and publication result.

**Stop condition:** Any failed or absent reviewer approval, version acceptance, source CI or packaging gate keeps PR draft and tag absent. This handoff is a decision support artifact, **not** a signed certification.
