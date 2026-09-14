# Runwire 1.0 — Foundation 3 Launch Plan

## Status

Target release: **Runwire 1.0**  
Active branch: `feature/runwire-1.0`  
Primary launch consumer: **Foundation 3**  
Related integrations after release: **Webrick** and **Omnibus**  
PHP baseline: **64-bit PHP ^8.4**

Implementation hardening and public-documentation closure are complete for the 1.0 release line. Release readiness remains an **exact-head property**: the commit that is actually approved/merged/tagged must pass the complete certification matrix below, and release actions remain explicitly human-controlled.

## 1. Final hardening status

The following release blockers are implemented on the branch and must remain covered by exact-head certification:

- supplementary-group-safe worker privilege dropping:
  - resolve target passwd identity;
  - derive the target base GID when only UID is configured;
  - initialize supplementary groups before changing primary GID/UID;
  - set GID before UID;
  - verify final effective UID/GID;
  - fail closed on any lookup/transition/verification error;
- ProcessRunner no longer depends on PCNTL signal constants;
- dedicated genuine no-PCNTL PHP 8.4/8.5 portable-native CI;
- portable-native HTTP/1.1, framed TCP, UDP, and supported Unix-socket acceptance;
- portable fail-closed checks for unsupported multi-worker, recycle, control, watcher, lifecycle-listener, privilege-drop, and HTTP/3-without-QUIC configurations;
- root native-prefork security diagnostic when no worker privilege-drop policy is configured;
- hostile-input acceptance for native HTTP/1.1 plus existing HTTP/2/HTTP/3 abuse suites;
- framed-protocol bounds plus UDP hostile-input/callback-failure acceptance;
- persistent request-state isolation acceptance across normal, cancellation, deadline and handler-failure paths;
- dedicated runtime hardening guidance in `docs/security.md`.

The following 1.0 policy decisions are frozen:

- `ProcessPolicy::allowedExecutables = null` remains the usability default and means any validated absolute executable path; security-sensitive applications should configure an explicit executable allowlist;
- portable native remains a single-process runtime and does not emulate prefork recycling;
- `WorkerRecyclePolicy::maxMemoryBytes` measures PHP allocator memory, not RSS/cgroup/native-extension/kernel memory;
- a separate single-process `ProcessRetirementPolicy` is deferred until real production need is demonstrated.

## 2. Documentation closure

Public documentation must describe implemented 1.0 behavior rather than pre-release assumptions or stale fallback claims. The documentation set is:

- `README.md`;
- `docs/getting-started.md`;
- `docs/architecture.md`;
- `docs/deployment.md`;
- `docs/security.md`;
- `docs/coroutines.md`;
- `docs/benchmarks.md`;
- this launch plan.

The synchronized documentation contract includes:

- native prefork versus genuine portable-native ownership/capability behavior;
- fail-closed portable configuration rules;
- supplementary-group-safe privilege dropping before bootstrap/readiness;
- ProcessRunner's no-shell/no-PCNTL runtime contract and executable-policy default;
- TLS and HTTP/3 fail-closed behavior;
- allocator-memory versus RSS/cgroup semantics for worker recycling;
- persistent-request-state cleanup requirements;
- bounded HTTP/framed/UDP/coroutine resource behavior;
- active-host AUTO detection versus explicit Swoole/OpenSwoole selection;
- Swoole/OpenSwoole host ownership through `RuntimeDriver::SWOOLE` and `SwooleOptions`;
- exact-head release certification and human-controlled merge/tag/publish sequence.

Security-sensitive operational details belong in `docs/security.md`; vulnerability reporting remains in `SECURITY.md`.

## 3. Final exact-head certification

Certify the **exact final branch head**. Any later source, workflow, test, or documentation commit invalidates earlier exact-head certification and requires a fresh run for the new head.

Required release-candidate gates:

- PHP 8.4 and PHP 8.5 QA green;
- prefer-stable and prefer-lowest dependency lanes green;
- PHPStan and Psalm green;
- clean `--no-dev` installation green;
- genuine no-PCNTL portable-native lane green on PHP 8.4 and PHP 8.5;
- native prefork acceptance green;
- benchmark workflow green on supported PHP versions;
- Swoole/OpenSwoole focused and live acceptance green for both supported host families;
- native HTTP/3 + QUIC protocol tests green on PHP 8.4 and PHP 8.5;
- aioquic interoperability green;
- ngtcp2/nghttp3 interoperability green where the lane applies;
- native HTTP/3 soak/drain acceptance green;
- hostile-input and persistent-state isolation acceptance green;
- dependency/security audit green;
- no unresolved release-blocking PR review findings.

Benchmark artifacts are regression evidence. They do not authorize a universal performance ranking without equivalent peer-runtime measurements.

## 4. Human release approval

CI success is necessary but not sufficient for release.

Before release:

1. review the final PR diff and public documentation;
2. confirm the exact-head checks above are green for the commit that will be merged/tagged;
3. confirm the package metadata and public API are intentional for 1.0;
4. explicitly approve the release.

Do not automatically merge, tag, or publish only because CI is green.

## 5. Merge, tag, and publish

After explicit approval:

1. merge the approved Runwire 1.0 branch;
2. create the agreed `1.0`/`v1.0` release tag;
3. publish the GitHub release/package metadata as appropriate;
4. verify Composer can resolve the released package from a clean project;
5. retain benchmark and interoperability evidence for the release head.

## 6. Foundation 3 integration

Only after Runwire 1.0 is released:

1. update Foundation 3 to the released Runwire constraint;
2. integrate through capability APIs rather than driver-name branching except for genuinely host-specific setup such as explicit Swoole/OpenSwoole selection;
3. validate request/application lifecycle and structured coroutine ownership;
4. run Foundation's complete QA and persistent-runtime acceptance suite;
5. then update Webrick and Omnibus where their Runwire integration requires the released behavior.

Runwire must remain framework-agnostic. Foundation, Webrick, and Omnibus behavior must not be pushed down into Runwire merely to simplify one integration.

## Release boundary

Runwire 1.0 is ready for human release approval only when Sections 1–3 are complete on the same exact head. Sections 4–6 remain explicitly human-controlled release/integration actions.
