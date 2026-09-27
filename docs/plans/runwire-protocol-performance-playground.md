# Runwire protocol performance playground

Updated: 2026-09-27

Branch: `benchmarks/1.0-vs-2.0`

Status: **first-stage protocol diagnostics complete; targeted validation in progress; production implementation intentionally paused**

## Objective

Use synthetic and real protocol workloads to locate transport, event-loop, framing, batching, polling, flow-control, lifecycle, and scaling limits before changing Runwire production behavior. Playground-only patches and fixtures are allowed. Findings must be reproduced before promotion into a production implementation plan.

## Tracker

| ID | Area | Diagnostic | Status |
| --- | --- | --- | --- |
| P0 | HTTP/1.1 | 1.0 vs 2.0 matched release comparison | Complete — no release regression detected |
| P1 | HTTP/1.1 | client/server/both TCP_NODELAY isolation | Complete — server side removes ~41 ms floor |
| P2 | Raw TCP | split small writes vs coalesced write, with/without TCP_NODELAY | Complete — split writes reproduce ~41 ms floor; coalescing removes it |
| P3 | HTTP/1.1 | EventLoop vs SelectLoop, payload and concurrency sweeps | Partial — both backends reproduce/fix the transport floor, but direct backend ranking is invalid because jobs landed on different CPUs |
| P4 | HTTP/1.1 TLS | TLS record/transport interaction with TCP_NODELAY | Complete diagnostic — same ~41 ms floor; NODELAY removes it until high-concurrency saturation |
| P5 | HTTP/1.1 | worker scaling at optimized transport setting | Complete diagnostic — 1→2 workers 1.43×, 1→4 workers 2.58× in the short run |
| P6 | HTTP/2 | real TLS + ALPN h2, sequential vs multiplexed streams, TCP_NODELAY | Complete diagnostic — low-stream/tiny-response path has same ~41 ms floor; high multiplex/payload can favor batching over NODELAY |
| P7 | HTTP/2 | payload and multi-connection scaling | Complete diagnostic — one connection already saturates one worker around the measured 5–6K RPS class; 4/16 connections do not improve it |
| P8 | HTTP/3 | QUIC poll-timeout sweep | Complete diagnostic — no evidence that 50 ms poll timeout creates a 50 ms active-traffic latency floor |
| P9 | HTTP/3 | response flush/write budget sweep | Complete diagnostic — 16 writes/flush looks restrictive; 64–512 is within short-run noise |
| P10 | HTTP/3 | stream-accept and concurrent-request limits | Complete diagnostic — no clear active-traffic bottleneck from 16–100 concurrent streams; repeated validation needed |
| P11 | HTTP/3 | payload-size sweep | Complete diagnostic — 2 B and 1 KiB are similar; 16 KiB shifts toward payload/crypto cost |
| P12 | Synthesis | classify confirmed bottlenecks and draft production implementation plan | In progress — targeted validation required before implementation |
| P13 | Peer crossmatch | Workerman / Coroutine / ReactPHP / Amp / Swoole / Swow source dissection | Complete — patterns recorded below |
| P14 | Peer crossmatch | same-runner H1 tiny-response reproduction for Workerman / ReactPHP / Amp | Scheduled |

## Evidence rules

- Benchmark harnesses remain PHP/Bash or pinned native protocol tools.
- Synthetic source changes are allowed only inside this playground branch/workflow.
- Exact workload parameters and raw output are uploaded as artifacts.
- Short runs identify causes; they do not become published capacity claims.
- No production behavior changes are promoted until the relevant diagnostic is reproduced with sustained trials.
- HTTP/3 is evaluated as QUIC/UDP; TCP_NODELAY is not applicable to it.
- HTTP/2 network throughput is measured through real TLS/ALPN negotiation rather than only in-memory frame tests.
- Cross-job absolute RPS comparisons are not treated as backend comparisons when GitHub assigns different CPU models.

## First-stage dissection

Successful evidence run: `36292404609` at `b06610f282163c585570bdf6bd849992e7f0b3b0`.

### F01 — split small TCP writes cause the ~41 ms floor

Confidence: **high**

Raw loopback transport results:

| TCP_NODELAY | Write shape | RPS | p95 |
| --- | --- | ---: | ---: |
| off | one coalesced write | 21,576.6 | 0.058 ms |
| off | two split writes | 24.5 | 41.047 ms |
| on | one coalesced write | 25,027.8 | 0.049 ms |
| on | two split writes | 22,698.0 | 0.050 ms |

The pathological case is specifically **split small writes + normal TCP coalescing behavior**. Either coalescing the application write or enabling TCP_NODELAY removes the floor. This makes write-shape optimization a first-class candidate rather than treating NODELAY as the only solution.

### F02 — native HTTP/1.1 reproduces the transport pathology

Confidence: **high**

On the EventLoop worker at concurrency 16:

| Payload | Default RPS / p95 | Server NODELAY RPS / p95 |
| --- | ---: | ---: |
| 2 B | 390.2 / 41.98 ms | 7,577.2 / 3.46 ms |
| 1 KiB | 390.7 / 41.95 ms | 7,133.7 / 3.77 ms |
| 16 KiB | 390.2 / 41.98 ms | 5,490.3 / 5.31 ms |

The default ~41 ms plateau is essentially payload-insensitive across 2 B–16 KiB. That matches the current `Http1ResponseWriter` path: `start()` writes the response head and `end()` writes the body separately.

SelectLoop independently reproduced the same default ~41 ms shape and the same NODELAY relief. Its absolute RPS must not be compared to EventLoop from this run because the SelectLoop job landed on an Intel Xeon Platinum 8573C while the EventLoop job landed on an AMD EPYC 7763.

### F03 — HTTP/1.1 TLS has the same root cause, then becomes CPU/crypto limited

Confidence: **high**

At concurrency 16, 2 B:

- default TLS: ~382 RPS, ~41.98 ms p95
- server NODELAY: ~5,820 RPS, ~3.92 ms p95

At concurrency 256 the NODELAY advantage disappears or reverses for TLS. With enough outstanding work the delayed-ACK floor is masked and TLS/CPU/queue pressure becomes the dominant constraint. This is evidence against treating “NODELAY always increases throughput” as a universal rule.

### F04 — worker scaling is useful but sublinear

Confidence: **medium; short diagnostic**

Optimized HTTP/1.1, 2 B, concurrency 256:

| Workers | RPS | Scale vs 1 worker | Approx. scaling efficiency |
| ---: | ---: | ---: | ---: |
| 1 | 5,950.7 | 1.00× | 100% |
| 2 | 8,534.0 | 1.43× | 71.7% |
| 4 | 15,374.9 | 2.58× | 64.6% |

Scaling is real, but there is enough coordination/socket/CPU overhead that worker count should be benchmarked rather than assumed linear.

### F05 — HTTP/2 has the same small-write latency problem, but global NODELAY is not universally optimal

Confidence: **high**

For 2 B responses on one real TLS+ALPN H2 connection:

| Concurrent streams | Default RPS / mean | NODELAY RPS / mean |
| ---: | ---: | ---: |
| 1 | 24.2 / 41.03 ms | 5,298.8 / 0.183 ms |
| 8 | 193.6 / 40.97 ms | 5,579.4 / 1.43 ms |
| 32 | 774.4 / 41.17 ms | 5,916.8 / 5.40 ms |
| 100 | 2,380.0 / 41.54 ms | 5,915.6 / 16.86 ms |

However, natural batching changes the result for larger payload/high multiplexing:

- 1 KiB at 100 streams: default **7,936.8 RPS** vs NODELAY **5,772.2 RPS**
- 16 KiB at 8 streams: default **5,649.6 RPS** vs NODELAY **5,043.0 RPS**
- 16 KiB at 32 streams: default **6,510.4 RPS** vs NODELAY **5,184.2 RPS**
- 16 KiB at 100 streams: default **6,788.2 RPS** vs NODELAY **5,312.0 RPS**

This is the strongest reason not to enable TCP_NODELAY blindly for every protocol. H2 likely wants frame/write coalescing for tiny responses while retaining batching benefits at higher stream/payload pressure.

### F06 — H2 single-connection throughput is not the limiting dimension

Confidence: **medium-high**

With NODELAY, 2 B, 32 streams:

- 1 connection: ~5,916.8 RPS
- 4 connections: ~5,992.2 RPS
- 16 connections: ~5,544.6 RPS

One connection is already enough to saturate the one-worker H2 path under this workload. The next useful scaling dimension is **workers**, not more client connections.

### F07 — the H2 10,000-stream churn guard is real and abrupt

Confidence: **high**

The dedicated 10,100-request probe produced:

- 10,000 successful responses
- 100 failed/errored requests
- 0 timeouts

`Http2Limits::maxStreamsPerConnection = 10_000` is enforced by raising `ENHANCE_YOUR_CALM` on the stream after the limit. The protection works, but the lifecycle semantics deserve review: a proactive graceful GOAWAY before the hard limit may be friendlier for healthy long-lived clients while preserving the abuse bound.

### F08 — H2 benchmark shutdown exposes noisy SSL broken-pipe warnings

Confidence: **high**

Several otherwise-correct H2 runs emitted:

`fwrite(): SSL: Broken pipe`

from both the immediate and buffered write paths in `Connection` when h2load closed at the measurement boundary. Expected peer disconnects should transition connection state without leaking PHP warnings into production logs. Any fix must preserve PHPForge’s no-silenced-errors rule; a scoped transport-warning handler is preferable to `@`.

### F09 — H3 default 50 ms poll timeout is not behaving like a 50 ms request latency floor

Confidence: **medium; current runs are very short**

512-request QUIC diagnostics:

- 1 ms poll: ~4,733 RPS
- 5 ms poll: ~4,345 RPS
- 10 ms poll: ~5,037 RPS
- 50 ms poll: ~4,819 RPS

There is no monotonic “shorter poll = faster” pattern and no 50 ms plateau. Active QUIC readiness is waking the worker; the poll timeout is primarily an idle bound in this workload.

### F10 — H3 scheduler knobs are not yet proven bottlenecks

Confidence: **low-medium; ~100 ms samples are noisy**

- writes/flush: 16 ≈ 4,331 RPS; 64 ≈ 4,939; 128 ≈ 4,650; 512 ≈ 5,011
- streams accepted/pump: 8 ≈ 4,912; 32 ≈ 4,775; 64 ≈ 4,387; 128 ≈ 5,111
- concurrent streams: 16 ≈ 5,007; 32 ≈ 4,948; 64 ≈ 4,769; 100 ≈ 5,002

Only `maxWritesPerFlush = 16` looks consistently suspicious. The rest is non-monotonic enough that tuning now would be guessing.

Payload behavior is clearer:

- 2 B: ~4,915 RPS
- 1 KiB: ~5,002 RPS
- 16 KiB: ~3,195 RPS

The 16 KiB result corresponds to roughly 52 MB/s of response body throughput, so larger-payload cost is expected to become visible.

## Targeted validation stage

| ID | Question | Validation |
| --- | --- | --- |
| V1 | Can H1 eliminate the floor without unconditional NODELAY? | Benchmark real `Http1ResponseWriter` with benchmark-only head+body coalescing: default vs NODELAY vs coalesce vs both, plain + TLS |
| V2 | Can H2 keep batching benefits while fixing tiny responses? | Benchmark real H2 HEADERS+first-DATA coalescing: default vs NODELAY vs coalesce vs both across 2 B / 1 KiB / 16 KiB and 1/8/32/100 streams |
| V3 | Is EventLoop actually faster/slower than SelectLoop? | Alternate both backends on the same runner, same PHP/extensions/OPcache, five repeated trials |
| V4 | Are any H3 tuning knobs real? | Replace ~100 ms samples with five repeated multi-thousand-request trials and report median/CV |
| V5 | Does H2 scale with workers after the protocol fix? | Run worker scaling only after V2 identifies the winning transport/write strategy |
| V6 | Should stream-churn limits rotate gracefully? | Preserve hard abuse bounds but test graceful pre-limit GOAWAY/connection rotation semantics for H2 and analogous H3 behavior |
| V7 | Can expected peer-close writes be quiet without suppression? | Reproduce SSL peer-close writes and design a scoped warning-to-write-failure transport helper |

Production implementation remains blocked on V1–V4. V5–V7 can then be folded into the implementation plan according to evidence.

## Harness remediation log

- First protocol-playground run `36290813137`: raw TCP and HTTP/3 completed; HTTP/1 EventLoop, HTTP/1 SelectLoop and HTTP/2 failed.
- HTTP/1 failures were benchmark validation defects: the sustained client still required the historical literal body `ok` while payload sweeps intentionally returned arbitrary-sized bodies. The client now accepts an explicit expected body from the playground runner while retaining `ok` as its normal default.
- HTTP/2 did not expose its underlying failure because the shell aborted directly on a non-zero h2load command. The runner now preserves and prints warm-up/measured h2load output plus the matching Runwire server log before failing.
- Second protocol-playground run `36291496503`: HTTP/1 workers still failed. Generic assertion output was insufficient to distinguish response validation from request accounting or timeouts. The client/runner now emits exact counters plus first-response status/body length/hash diagnostics and the matching server log on failure.
- The previous remediation did not rewrite the compacted H1/H2 shell runners because its text anchors did not match their normalized form. Both runners were replaced wholesale. HTTP/1 now passes payload expectations and always prints client/server diagnostics; HTTP/2 now checks ALPN on combined OpenSSL output and always prints h2load/server diagnostics.
- Corrected run `36291727823`: Raw TCP, HTTP/1 SelectLoop, HTTP/1 EventLoop/TLS/scaling, and HTTP/3 passed. HTTP/2 reached exactly 10,000 successful responses on one connection at 100-way multiplexing, then hit the production default stream-churn guard.
- H2 steady-state throughput uses a playground-only `maxStreamsPerConnection = 1_000_000` so the churn policy does not cap throughput measurements; the production 10,000-stream boundary remains a separate probe.
- h2load duration accounting was corrected so a final started/in-flight response at the duration boundary does not become a false correctness failure.
- The H2 steady-state env override initially missed a compacted shell line; the exact launch command is now asserted before commit.


## PHP ecosystem crossmatch

Source revisions inspected:

| Project | Revision | Relevant finding |
| --- | --- | --- |
| walkor/workerman | `a64a81b53231d5133e3869898a46c2953f9db593` | disables Nagle on TCP sockets and emits buffered HTTP/1 headers+body as one encoded string |
| workerman-php/coroutine | `918c10be5270a74f91ff77cc66b6a821cc456a25` | transport-neutral coroutine facade selecting Fiber/Swoole/Swow drivers; no TCP/HTTP write policy |
| amphp/socket | `b347be5aff6b2cc025208bb4d896607eb470c018` | first-class `withTcpNoDelay()`; default is false |
| amphp/byte-stream | `fd8db31affb68d1f389dde28b3c844499b7912dc` | scoped error handler around `fwrite()`; warnings become stream errors without `@` |
| amphp/http-server | `8a971bf92cf8cf2bc511f37a75b39126d5305315` | documents Nagle hurting tiny-response benchmarks; H1 writes head then body; H2 has GOAWAY graceful shutdown and waits pending responses/writes |
| reactphp/socket | `d5a375f8754da6aaf478a3bee5c7f5866ea893e1` | socket context is passed through; no forced TCP_NODELAY default |
| reactphp/stream | `430a9dfbea8fc63f1ab06d20f5a600177c621b7d` | write buffering plus scoped `fwrite()` warning handling |
| reactphp/http | `bb151a7cdf9e7b49caac9030add52c6427ebc6d2` | non-streaming HTTP/1 response path explicitly writes `headers + body` in one call |
| swoole/swoole-src | `8fbc62cf6fac327d6a1b25c962625285d8917e55` | server-port configuration defaults `open_tcp_nodelay` to true when unspecified; native HTTP/2 support exists |
| swow/swow | `70ec0abde96c79a0ada4857217e0e07eb128d033` | libcat explicitly enables TCP_NODELAY by default for TCP sockets |

### Crossmatch conclusions

1. **H1 coalescing is established practice.** Workerman and ReactPHP both serialize buffered/non-streaming headers and body into one write path. This independently supports Runwire V1.
2. **High-performance runtimes commonly choose NODELAY.** Workerman explicitly disables Nagle; Swoole server ports default NODELAY on; Swow/libcat states TCP is nodelay by default. This supports exposing a first-class Runwire transport option, but Runwire H2 evidence still argues against blindly forcing the same policy for every protocol/write shape.
3. **Amp explicitly recognizes the exact benchmark pathology.** Its HTTP Server README notes that Nagle can negatively affect benchmarks with very small responses and recommends `BindContext::withTcpNoDelay()` for that case, while noting it is generally unnecessary for production servers. This closely matches Runwire's observed default-vs-NODELAY behavior.
4. **V7 has strong precedent.** Amp and React both wrap the actual `fwrite()` call in a scoped temporary error handler and convert warnings into stream-level failure semantics. Workerman uses `@fwrite`, which is not suitable for Runwire's PHPForge rules.
5. **Graceful H2 GOAWAY is established behavior.** Amp's H2 shutdown writes GOAWAY, waits pending responses, then waits pending writes before closing. This is useful precedent for Runwire V6's proposed pre-limit graceful rotation.
6. **Workerman Coroutine does not change transport conclusions.** Its role is driver selection/coroutine primitives, not packetization or protocol framing.
7. **No mature native H3 precedent surfaced** in the inspected Workerman, Coroutine, ReactPHP HTTP, Amp HTTP Server, Swoole, or Swow repositories. Runwire H3 decisions should remain evidence-driven from its own QUIC implementation rather than copied from an unrelated stack.

### Peer reproduction benchmark

A same-runner diagnostic is scheduled for:

- Runwire default and server-NODELAY
- Workerman default
- ReactPHP default and socket-context NODELAY
- Amp default and `BindContext::withTcpNoDelay()`

All cases use PHP 8.5, portable PHP loop paths, concurrency 16, a two-byte keep-alive response, and the same Runwire load client. The purpose is **root-cause crossmatch, not framework ranking**. Absolute RPS differences are secondary; the key signal is whether the ~41 ms p95 floor appears or disappears.
