# Runwire protocol performance playground

Updated: 2026-09-27

Branch: `benchmarks/1.0-vs-2.0`

Status: **diagnostic workers scheduled; first run exposed harness failures; remediation queued, production implementation intentionally paused**

## Objective

Use synthetic and real protocol workloads to locate transport, event-loop, framing, batching, polling, flow-control, and scaling limits before changing Runwire production behavior. Playground-only patches and fixtures are allowed. Findings must be reproduced before promotion into a production implementation plan.

## Tracker

| ID | Area | Diagnostic | Status |
| --- | --- | --- | --- |
| P0 | HTTP/1.1 | 1.0 vs 2.0 matched release comparison | Complete — no release regression detected |
| P1 | HTTP/1.1 | client/server/both TCP_NODELAY isolation | Complete — server side removes ~41 ms floor |
| P2 | Raw TCP | split small writes vs coalesced write, with/without TCP_NODELAY | Scheduled |
| P3 | HTTP/1.1 | EventLoop vs SelectLoop, payload and concurrency sweeps | Scheduled |
| P4 | HTTP/1.1 TLS | TLS record/transport interaction with TCP_NODELAY | Scheduled |
| P5 | HTTP/1.1 | worker scaling at optimized transport setting | Scheduled |
| P6 | HTTP/2 | real TLS + ALPN h2, sequential vs multiplexed streams, TCP_NODELAY | Scheduled |
| P7 | HTTP/2 | payload and multi-connection scaling | Scheduled |
| P8 | HTTP/3 | QUIC poll-timeout sweep | Scheduled |
| P9 | HTTP/3 | response flush/write budget sweep | Scheduled |
| P10 | HTTP/3 | stream-accept and concurrent-request limits | Scheduled |
| P11 | HTTP/3 | payload-size sweep | Scheduled |
| P12 | Synthesis | classify confirmed bottlenecks and draft production implementation plan | Waiting for workers |

## Evidence rules

- Benchmark harnesses remain PHP/Bash or pinned native protocol tools.
- Synthetic source changes are allowed only inside this playground branch/workflow.
- Exact workload parameters and raw output are uploaded as artifacts.
- Short runs identify causes; they do not become published capacity claims.
- No production behavior changes are promoted until the relevant diagnostic is reproduced with sustained trials.
- HTTP/3 is evaluated as QUIC/UDP; TCP_NODELAY is not applicable to it.
- HTTP/2 network throughput is measured through real TLS/ALPN negotiation rather than only in-memory frame tests.

## Existing confirmed finding

For the two-byte HTTP/1.1 response at concurrency 16, default server TCP behavior produced roughly 392 RPS with ~41 ms p95. Server-side TCP_NODELAY removed that floor and raised short-run throughput into the ~7K RPS class. Client-only TCP_NODELAY did not materially change the result. These remain diagnostic numbers, not release-certified capacity.

## Harness remediation log

- First protocol-playground run `36290813137`: raw TCP and HTTP/3 completed; HTTP/1 EventLoop, HTTP/1 SelectLoop and HTTP/2 failed.
- HTTP/1 failures were benchmark validation defects: the sustained client still required the historical literal body `ok` while payload sweeps intentionally returned arbitrary-sized bodies. The client now accepts an explicit expected body from the playground runner while retaining `ok` as its normal default.
- HTTP/2 did not expose its underlying failure because the shell aborted directly on a non-zero h2load command. The runner now preserves and prints warm-up/measured h2load output plus the matching Runwire server log before failing.

- Second protocol-playground run `36291496503`: HTTP/1 workers still failed. Generic assertion output was insufficient to distinguish response validation from request accounting or timeouts. The client/runner now emits exact counters plus first-response status/body length/hash diagnostics and the matching server log on failure.

- The previous remediation did not rewrite the compacted H1/H2 shell runners because its text anchors did not match their normalized form. Both runners are now replaced wholesale. HTTP/1 now passes payload expectations and always prints client/server diagnostics; HTTP/2 now checks ALPN on combined OpenSSL output and always prints h2load/server diagnostics.
