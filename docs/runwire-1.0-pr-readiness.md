# Runwire 1.0 PR Readiness

This document records the Runwire 1.0 hardening evidence carried by PR #2. It is **not** a release authorization.

The branch must remain unmerged and untagged until an explicit human approval is given after PR review. No Runwire 1.0 package/release publication is implied by a green PR.

## Independently certified implementation batches

| Batch | Certified head | Benchmarks | Security & Standards |
| --- | --- | --- | --- |
| 0 | `1868a82fee0e8a35b7e94131bc45c5f729983f1b` | #16 | #203 |
| A | `a95c743ca8efa0b1b024526b817d79db0d466da4` | #24 | #211 |
| B | `921b3589d1af04998b54f8bcf8e678e7b2a3912f` | #27 | #214 |
| C | `04c97cc716679e19e0d4c3e591cafc2844a59395` | #32 | #219 |
| D | `47cfb92486c86c1bd7a08283c9c5e5cbd360efa9` | #39 | #226 |
| E | `e975e28ecbb0ce4c160887018ebb910b60edc34f` | #43 | #230 |
| F | `597bde0e6920790cc63b419d21726092ce9646a6` | #46 | #233 |
| G | `afb321517bf453b0db5f00c0db382ac42d8e881e` | #49 | #236 |
| H | `8eb2b20342130db73e98c8aee1e68235180d7c8b` | #52 | #239 |
| I | `1d1ff98cb53facf39b9af98cd7a6cc2c559fb7a9` | #56 | #243 |

For each batch above, the corresponding Security & Standards workflow completed the applicable PHP 8.4/8.5 prefer-stable/prefer-lowest QA, PHPStan/Psalm analyzers, clean install and native QUIC/HTTP/3 interoperability lanes.

Batch I additionally closes the cross-driver lifecycle parity and expanded soak/fault acceptance program, including request-context/reset/cancellation churn, recycle triggers, rolling reload/reaping, overload recovery and capability-gated reuse-port coverage.

## Batch J / final PR-head gate

Batch J refreshes benchmark coverage, operational documentation and final PR-head certification. Its authoritative evidence is the Benchmarks and Security & Standards checks attached to the final PR head after all J/tracker documentation is committed.

The final PR head must have:

- PHP 8.4 and PHP 8.5 PHPForge QA green;
- prefer-stable and prefer-lowest dependency lanes green;
- PHPStan and Psalm green;
- clean install green;
- native QUIC/HTTP/3 PHP 8.4 and PHP 8.5 lanes green;
- aioquic interoperability green;
- ngtcp2/nghttp3 interoperability green where the lane applies;
- dedicated PHPBench workflow green with protocol, host-adapter and lifecycle/resource subjects.

## Performance evidence boundary

Runwire-only CI benchmarks are regression evidence, not a universal runtime ranking. Public cross-runtime performance positioning requires equivalent real-runtime records with the same hardware, PHP version, protocol, workload, worker count, concurrency and duration plus throughput, p50/p95/p99, errors, CPU and RSS.

`benchmarks/comparative_evidence.php` validates those records and refuses mismatched comparisons. Missing peer-runtime evidence must remain missing; it must never be filled with estimates or results copied from unrelated public benchmarks.

## Explicit release block

Even after Batch J and the final PR-head checks are green:

1. keep PR #2 open until human review/approval;
2. do not merge this branch automatically;
3. do not create a `1.0`/`v1.0` tag automatically;
4. do not publish a GitHub/Composer release automatically;
5. do not begin Foundation/Webrick/Omnibus integration from an unreleased Runwire head unless separately authorized.

Release is a separate explicit approval step outside this PR-completion task.
