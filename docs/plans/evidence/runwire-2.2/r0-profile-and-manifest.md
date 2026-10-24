# Runwire 2.2 — R0 provenance, profile triage and calibrated acceptance

**Status:** Published predecessor frozen; real CI snapshots recovered. Diagnostic hotspot/queue curves captured, but statistically stable production-equivalent profiling and concurrency promotion remain pending.

## Exact references

| Field | Verified value |
| --- | --- |
| Last published | `2.1.2`, annotated tag resolves to `7e9bf20ad0737a187dfed77b723791b141b36378` |
| Candidate starting tree | `92d8ea52bf31e1adfb2f70ac981ebbf4f328558d` — roadmap documentation only |
| Last fully green QA | `d4bfad4f96d003ae753ad1c3e5512bd70e71e1ff` — PHP 8.4/8.5, native, Swoole, benchmarks |
| Current performance-gate candidate | `82698e555538788e820663cee8d46efedf5746b2`; comparison tests green but real QUIC PHP 8.4 flaked, so not release ready |
| Published baseline commit | identical to `main` at plan creation; no earlier code changes in base revision |
| PHPForge lock at previous candidate | generated CI `composer.lock` SHA256 `bd019b2bceb243d423fb9d0906ccbe7a8afb9b1de1c8cd78367f4c65134d6238`; `infocyph/phpforge dev-main a595f8d` |
| Benchmark source provenance | Pull requests may use GitHub synthetic merge SHA; artifact `runtime_build` identifies that SHA rather than guaranteeing an independent branch HEAD |

## Reproducible PHP runner observations

| Environment | PHP8.4 runner | PHP8.5 runner |
| --- | --- | --- |
| PHP binary | 8.4.26, non-thread-safe | 8.5.11, non-thread-safe |
| CPU model | AMD EPYC 9V45 96-Core Processor | AMD EPYC 7763 64-Core Processor |
| Available guest vCPUs | 4 | 4 |
| Linux | 6.17.0-1022-azure x86_64 | 6.17.0-1022-azure x86_64 |
| Composer | 2.10.3 | 2.10.3 |
| ext-event | 3.1.6 | 3.1.6 |
| OPcache | enabled in benchmark CLI | enabled in benchmark CLI |
| Selected dependency lock | identical generated SHA | identical generated SHA |
| cgroup CPU/memory quota | not surfaced by the current CI collection step | not surfaced by the current CI collection step |

Evidence ZIPs: PHP8.4 `runwire-benchmarks-php-8.4` artifact `12238472805` SHA256 `bdda40fc06ee70b7304d5b912b219754b3dc21e53081f0997f0125761fd904ff`; PHP8.5 `runwire-benchmarks-php-8.5` artifact `12238103879` SHA256 `5d0d30c3e4f9cef4065b47796a794a4e0f82ff6d45e6913341e345f24750390a`; [CI 39364887846](https://github.com/infocyph/Runwire/actions/runs/39364887846).

**Missing:** host memory/CPU cgroup limits must be captured or explicitly shown absent, and full source tree clean/diff verification recorded for a final candidate. Therefore R0.01 stays unchecked.

## Profile candidates from actual PHPBench artifacts (within PHP 8.5 runner)

Units below are PHPBench **mode µs per isolated operation**, not an additive request profile. Comparisons between separate subjects and different PHP versions do not establish production impact.

| Candidate | Mode (µs) | Location / next evidence |
| --- | ---: | --- |
| HTTP3 QPACK dynamic round trip | 298.86 | R4: realistic frame mix + interop; not general H1 bottleneck |
| Coroutine task-group spawn/join | 173.11 | R2: actual tasks per HTTP request and bounded backlog |
| Coroutine unbuffered channel handoff | 170.47 | R2: channel contention under real shared scopes |
| HTTP2 HPACK decode | 47.11 | R4: H2 multiplexing load, sample header size |
| Hosted application dispatch/cleanup | 21.92 | R1: end-to-end hosted cost, handler vs lifecycle |
| Host request normalization | 13.91 | R1: methods/headers/body and avoidable conversion |
| Context creation and completion | 3.90 | R1: request-ID cost, deadline and reset |
| HTTP1 head validation | 3.32 | R3: per keep-alive request parser bounds |
| Host response writer lifecycle | 2.06 | R1: callback/terminal only if full request gain |

Sample PHP8.5 host-adapter: 5 PHPBench iterations with 1,000 revs, 1 warmup, from the identical recorded artifact; the real network client and handler composition are not included.

**R0.06 outstanding:** To call these verified hot spots by end-to-end impact, capture one actual on-CPU request-path profile with wall/CPU attribution across framework bootstrap, protocol parser, context creation, handler, response, syscall/poll and scheduler; correlate with successful RPM, CPU and queue pressure. No production `src/` optimization should be attributed to the isolated operation table.

## Real H1/H2 concurrency diagnostic curves (same runner, five repeats)

Source: `runwire-adaptive-http12`, artifact `12238979021`, SHA256 `124a235ce6495820d4f3e230aa9058134a8f6ab9ccd47872067d5453b3eebb75`, [CI 39364887846](https://github.com/infocyph/Runwire/actions/runs/39364887846). One PHP 8.5.11 runner, H1 2-byte plaintext keepalive, H2 768-byte TLS. Modes are adaptive AUTO and configured FIXED; this is **not** a 2.1.2-vs-2.2 comparison.

| Protocol / mode | Concurrency 8 low before (RPM, p99) | High concurrency (RPM, p99) | High RPS CV |
| --- | --- | --- | --- |
| H1 fixed | 1,121,045; 0.82ms | c256: 929,699; 26.72ms | 13.245% |
| H1 auto | 1,049,258; 1.29ms | c256: 840,498; 26.67ms | 11.845% |
| H2 fixed | 380,883; 2.22ms | c64: 363,469; 16.35ms | 4.230% |

These are diagnostically useful: H1 large concurrency increases p99 substantially even when no response fails; CV far exceeds the 2.5% certification ceiling. The five repeats used 1-second warmup and 5–7-second primary phases, not release-grade 30s warmup / 180s measured phases. The adaptive comparison expressly reports `promotion_certified=false`.

**R0.07 outstanding:** reproduce real 2.1.2 baseline with comparable H1/H2 concurrency sweeps on one host, prove load generator headroom, longer phases, bounded queues/FDs and matched CPU/cgroup settings. Only then choose runtime optimizations and claim measured successful-RPM gains.

## R0.10 release regression safety policy

Existing adaptive comparator already uses these ceilings. Applying the same policy to the release comparator avoids inventing a new threshold:
- Successful RPS/RPM: candidate no more than 5% below published 2.1.2 median, only enforce throughput when max CV is **strictly below 2.5%**; otherwise release job fails independently on `budget_enforced=false`.
- p99: fail when candidate exceeds **both** baseline×1.15 **and** baseline+0.5ms.
- Peak RSS: fail if candidate exceeds baseline×1.10 + 4 MiB.
- Valid response accounting: 100% of attempted responses successful, no errors, timeouts, invalid responses, or missing terminal ownership. No uncontrolled queue growth and no leaks.
- The same source/matched worker count, CPU/OS, OPcache, extensions, TLS and connection reuse must be checked before comparison.
- Keep long soak and Infbyte consumer separate required release gates. A one-time 30-minute soak pass does not substitute for final candidate qualification.

The original five ×180s paired baseline trial gave p99 [5.55, 5.54, 5.51, 5.61, 4.86]ms (13.54% full range / median) and candidate [5.69, 5.69, 5.67, 5.69, 5.70]ms. RSS varied under 0.004% within baseline trials. Reusing the previously proven guardrails is materially safer than relying on one p99 number or fabricating zero variance.

**Do not promote R0 complete** until genuine R0.01, R0.06, and R0.07 exit evidence exists and the full release blocker matrix is resolved.

## Scoped R0.06 profiler (2026-10-20, pending QA artifact)

- Added `benchmarks/r0_host_profile.php`: real HostRequestFactory normalization → CallbackResponseWriter setup → RuntimeApplication persistent dispatch/finalization on a single synthetic request path; validates HTTP 200, correct body, exactly-once end and request-context completion every sample.
- Includes 1,000 warmup iterations and 5,000 independent measured requests on PHP8.4 and 8.5 CI. Captures each phase's p50/p95/p99/mean microseconds, total wall seconds, PHP allocator delta/peak and process peak RSS where exposed. No instrumentation in production classes.
- Test `tests/R0HostProfileTest.php` exercises verified response completion, phase availability and rejects invalid sample bounds. Full CI artifact must pass before using numbers in an optimization decision.
- **Scope:** hosted synthetic in-process request path. No real socket, parser, syscalls, worker load, kernel scheduling, or framework bootstrap. This complements existing real native H1/H2 benchmark curves; it is not release-grade end-to-end CPU attribution. R0.06 remains open until missing native/host attribution is addressed.
