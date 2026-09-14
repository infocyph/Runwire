# Runwire 1.0 — Foundation 3 Launch Plan

## Status

Target release: **Runwire 1.0**  
Active branch: `feature/runwire-1.0`  
Primary launch consumer: **Foundation 3**  
Related integrations after release: **Webrick** and **Omnibus**  
PHP baseline: **64-bit PHP ^8.4**

This is the only active Runwire 1.0 plan. Completed implementation work, historical certification tables, and superseded readiness checklists are intentionally excluded.

The Runwire 1.0 implementation, including the portable-native fallback contract, is complete. This file tracks only the release actions that remain before Runwire 1.0 can be approved, merged, tagged, published, and consumed by Foundation 3.

## 1. Final exact-head certification

Certify the **exact final branch head**. Any later source/runtime change invalidates the certification and requires a fresh exact-head run.

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

## 2. Human release approval

CI success is necessary but not sufficient for release.

Before release:

1. review the final PR diff and documentation;
2. confirm the exact-head checks above are green;
3. confirm the package metadata and public API are intentional for 1.0;
4. explicitly approve the release.

Do not automatically merge, tag, or publish only because CI is green.

## 3. Merge, tag, and publish

After explicit approval:

1. merge the approved Runwire 1.0 branch;
2. create the agreed `1.0`/`v1.0` release tag;
3. publish the GitHub release/package metadata as appropriate;
4. verify Composer can resolve the released package from a clean project;
5. retain benchmark and interoperability evidence for the release head.

## 4. Foundation 3 integration

Only after Runwire 1.0 is released:

1. update Foundation 3 to the released Runwire constraint;
2. integrate through capability APIs rather than driver-name branching;
3. validate request/application lifecycle and structured coroutine ownership;
4. run Foundation's complete QA and persistent-runtime acceptance suite;
5. then update Webrick and Omnibus where their Runwire integration requires the released behavior.

Runwire must remain framework-agnostic. Foundation, Webrick, and Omnibus behavior must not be pushed down into Runwire merely to simplify one integration.

## Release boundary

Runwire 1.0 is ready for release only when Sections 1–2 are complete on the same exact head. Sections 3–4 occur only after explicit human authorization.
