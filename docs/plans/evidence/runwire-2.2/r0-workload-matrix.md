# Runwire 2.2 — R0 workload matrix and provenance

**Date:** 2026-10-19
**Status:** Workload definition complete; sustained calibrated implementation/certification **pending**.
**Published predecessor:** `2.1.2`, annotated tag resolves to `7e9bf20ad0737a187dfed77b723791b141b36378`.

## Definitive workload matrix (R0.05)

| Class | Scenario | Critical correctness | Proposed evaluation |
| --- | --- | --- | --- |
| **Primary native H1** | 2-byte plaintext GET `/benchmark`, keep-alive, one worker; then representative small JSON | HTTP 200, validated body, all requests completed | Warmed concurrency sweep 1/4/16/32/64, independent TCP client, repeated steady-state trials |
| H1 TLS | Same handler under OpenSSL and ALPN HTTP/1.1 | TLS negotiation, no plaintext fallback, full completion | Same controlled topology, TLS on; compare only within TLS |
| H2 TLS | Multiplexed streams per connection with 2-byte and JSON responses | HPACK, flow-control and sibling isolation, GOAWAY | 1/8/32/100 streams where within configured limits; independent H2 client |
| H3/QUIC | HTTP/3 over ext-quic with QPACK | No 0-RTT application dispatch, stalled flow-control, cancellation, interop | Supported QUIC/OpenSSL builds, independent aioquic and ngtcp2 clients |
| Streaming / bulk | 64KiB and 1MiB payload, chunked streaming | Bounded buffer, exact bytes, abort/drain | Single + concurrent slow/fast recipients |
| WebSocket | Live upgraded H1 sessions, fragmented frames, ping/pong | Masking, close codes, backpressure | Long-lived/churn mixes and soak |
| TCP + Unix | Line/length framing with nonblocking partial I/O | No truncated/overlength frames, stream closure | High/low concurrency and slow readers |
| UDP | Valid and oversized datagrams | Error/admission bounds, no memory leak | Burst and sustained packet traffic |
| Coroutines | Sleep/wake, channels, cancellation, bounded synchronization | No deadlock/lost wake, fairness, max-ready limits | Timers + network + CPU mixed saturation |
| Worker lifecycle | Native prefork reload/recycle and portable fallback | Exactly-once reset, worker generation, drain | Requests held across recycle, worker SIGKILL, 30-minute soak |
| Hosted modes | FPM, FrankenPHP classic/worker, RoadRunner, Swoole, OpenSwoole | No double reactor/listener, request cleanup, postfork ownership | True host acceptance plus parity probes |
| Consumer | Infbyte representative application and Foundation/Webrick bridge | Real route body/status, no isolation leaks | Full CI consumer test and representative route profiling |

Mandatory per-scenario fields: exact baseline/candidate SHA, PHP binary/patch version, extensions, OPcache, OS/CPU, worker count, payload, TLS/keepalive, run duration, concurrency, correct successful RPS/RPM, p50/p95/p99, CPU, RSS, errors, timeouts, queue/fd metrics and load-generator saturation.

**Sampling:** existing CI 5 × 3-second runs are diagnostics only. The release-grade matched comparison is 5 × 180-second measured trials with warmup on the **same runner/hardware** (the existing release-certification harness). Do not use archived CI smoke measurements to advertise 2.2 gains.

## Retrieved historical smoke artifacts (R0.07 / R0.08 investigation)

| PHP / revision | Runner CPU | Successful RPM (median) | p99 ms | Peak RSS bytes | RPS CV |
| --- | --- | ---: | ---: | ---: | ---: |
| 8.4.26 / published 2.1.2 | AMD EPYC 7763 | 376,560.06 | 5.83 | 111,251,456 | 8.944% |
| 8.4.26 / earlier PR merge commit `40d28d3f` | AMD EPYC 7763 | 372,298.02 | 5.72 | 111,300,608 | 9.577% |
| 8.5.11 / published 2.1.2 | AMD EPYC 7763 | 371,503.74 | 5.73 | 123,957,248 | 13.541% |
| 8.5.11 / earlier PR merge commit `40d28d3f` | Intel Xeon Platinum 8573C | 943,498.68 | 2.08 | 124,694,528 | 6.436% |

- Historical baseline workflow: https://github.com/infocyph/Runwire/actions/runs/39005210440 (artifacts `12090407191`, `12091220655`).
- Earlier PR benchmark workflow: https://github.com/infocyph/Runwire/actions/runs/39227015117 (artifacts `12173964049`, `12174481540`).
- All four records are 5 repeated **3-second** trials at concurrency 16 with one native worker, the existing 2-byte `text/plain` benchmark handler, and recorded CLI OPcache enabled.
- The PR benchmark measures GitHub's synthetic pull-request **merge commit**, not necessarily the source branch SHA. That is normal for default `pull_request` checkout; this must not be mislabeled as a branch-head optimization result.
- PHP 8.5 compared **different CPU models**; invalid A/B. Even same-model PHP 8.4 smoke results have high RPS CV and separate runner instances; cannot establish wins/losses.
- The previous CI run proves correctness of these sample responses only; it does not establish production capacity or eliminate load-generator saturation. Verify CPU throttling, process count and concurrency knee on a dedicated, stable runner.

## R0 acceptance and remaining work

- Existing sustained benchmark scripts already validate complete response accounting. Comparator now fails closed on mismatched topology and reports p99 and RSS deltas; CI still needs to execute against final candidate.
- Only measured same-topology trials qualify as A/B evidence; `successful RPM` remains primary KPI.
- Existing release throughput regression budget is 5%, gated on CV below 2.5%. Distinct p99/RSS budgets must be selected **after** stable measurements. Current smoke CV of 6–14% exceeds that stability criterion.
- Continue to R1 only when R0 real baseline, profile rankings, full QA, exact SHAs and bound budgets have evidence. The workload definition task R0.05 itself is complete; executing the full matrix is not.
