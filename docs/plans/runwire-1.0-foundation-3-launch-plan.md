# Runwire 1.0 — Foundation 3 Launch Plan

## Status

Target release: **Runwire 1.0**  
Active branch: `feature/runwire-1.0`  
Primary launch consumer: **Foundation 3**  
Related integrations after release: **Webrick** and **Omnibus**  
PHP baseline: **64-bit PHP ^8.4**

This is the only active Runwire 1.0 plan. Completed implementation batches, historical certification tables, and superseded PR-readiness checklists have been removed from the active plan.

The implementation is substantially complete. This file tracks only work that still has to be completed before Runwire 1.0 can be approved, merged, tagged, published, and consumed by Foundation 3.

## 1. Portable-native contract cleanup

Before final release certification, close the remaining behavior gaps between the documented 1.0 capability contract and the current portable native execution path.

### 1.1 Reject unsupported explicit multi-worker topology

Portable native mode is intentionally single-process.

Required final behavior:

- `workers: 0` resolves to one portable worker;
- `workers: 1` runs normally;
- explicitly configured `workers > 1` must fail with a clear `RuntimeUnavailableException` when prefork capability is unavailable;
- never silently clamp an explicit multi-worker request to one process.

Apply the same rule consistently to HTTP, framed stream, and UDP server definitions.

### 1.2 Reject unsupported worker-recycle configuration in portable mode

`RuntimeCapabilities::supportsWorkerRecycle` is false in portable native mode. Explicit recycle thresholds must therefore not be silently ignored.

Required final behavior:

- default/disabled recycle policy remains valid;
- an enabled request-count, lifetime, or memory recycle threshold without prefork support fails clearly before serving;
- the portable graceful shutdown timeout may continue to use the shared graceful timeout value without advertising worker replacement capability.

### 1.3 Fail explicit HTTP/3 configuration when QUIC is unavailable

HTTP/3 is an explicit protocol contract and must not silently disappear.

Required final behavior:

- an HTTP server without `Http3Options` continues with HTTP/1.1/HTTP/2 as available;
- a server configured with `Http3Options` requires QUIC capability;
- when QUIC is unavailable, startup fails clearly instead of skipping the HTTP/3 attachment;
- TLS/HTTP/3 validation remains explicit and bounded;
- existing QUIC-present portable and prefork behavior must remain unchanged.

### 1.4 Regression tests

Add/retain focused coverage proving:

- portable `workers: 0` and `workers: 1` work;
- portable `workers > 1` fails;
- enabled recycle thresholds fail when worker replacement is unavailable;
- explicit HTTP/3 without QUIC fails;
- HTTP/1.1/HTTP/2 continue normally when HTTP/3 is not configured;
- privilege-drop validation remains rejected in portable mode through runtime capability selection;
- control/watch/supervisor lifecycle configuration remains rejected in portable mode;
- portable graceful stop still drains HTTP, framed stream, UDP, and QUIC attachments correctly.

## 2. Final exact-head certification

After the portable-native cleanup above and any documentation-only follow-up, certify the **exact final branch head**. Any later source/runtime change invalidates that certification and requires a fresh exact-head run.

Required release-candidate gates:

- PHP 8.4 and PHP 8.5 QA green;
- prefer-stable and prefer-lowest dependency lanes green;
- PHPStan and Psalm green;
- clean `--no-dev` installation green;
- benchmark workflow green on supported PHP versions;
- Swoole/OpenSwoole focused acceptance green;
- native HTTP/3 + QUIC protocol tests green where QUIC is present;
- aioquic interoperability green;
- ngtcp2/nghttp3 interoperability green where the lane applies;
- native HTTP/3 soak/drain acceptance green;
- no unresolved release-blocking PR review findings.

Benchmark artifacts are regression evidence. They do not authorize a universal performance ranking without equivalent peer-runtime measurements.

## 3. Human release approval

CI success is necessary but not sufficient for release.

Before release:

1. review the final PR diff and documentation;
2. confirm the exact-head checks above are green;
3. confirm the package metadata and public API are intentional for 1.0;
4. explicitly approve the release.

Do not automatically merge, tag, or publish only because CI is green.

## 4. Merge, tag, and publish

After explicit approval:

1. merge the approved Runwire 1.0 branch;
2. create the agreed `1.0`/`v1.0` release tag;
3. publish the GitHub release/package metadata as appropriate;
4. verify Composer can resolve the released package from a clean project;
5. retain benchmark and interoperability evidence for the release head.

## 5. Foundation 3 integration

Only after Runwire 1.0 is released:

1. update Foundation 3 to the released Runwire constraint;
2. integrate through capability APIs rather than driver-name branching;
3. validate request/application lifecycle and structured coroutine ownership;
4. run Foundation's complete QA and persistent-runtime acceptance suite;
5. then update Webrick and Omnibus where their Runwire integration requires the released behavior.

Runwire must remain framework-agnostic. Foundation, Webrick, and Omnibus behavior must not be pushed down into Runwire merely to simplify one integration.

## Release boundary

Runwire 1.0 is ready for release only when Sections 1–3 are complete on the same exact head. Sections 4–5 occur only after explicit human authorization.
