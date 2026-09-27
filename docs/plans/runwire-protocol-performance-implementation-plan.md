# Runwire protocol performance implementation decision plan

Updated: 2026-09-27

Branch: `benchmarks/1.0-vs-2.0`

Status: **draft decisions driven by benchmark evidence; production implementation blocked until validation gates close**

Companion evidence tracker: `docs/plans/runwire-protocol-performance-playground.md`

## Objective

Turn the protocol playground findings into explicit, reviewable production decisions for Runwire. Every finding must end in one of three outcomes:

- **Implement** — evidence proves a production change is beneficial and safe.
- **Keep current behavior** — the suspected issue is not reproducible or the trade-off is unfavorable.
- **Drop** — the candidate is not capable enough to justify added code or complexity.

No item is deferred merely because it is difficult. If a candidate survives validation, implement it properly in Runwire; otherwise close it with evidence.

## Decision rules

- Performance comes first, then scalability, while preserving protocol correctness and bounded resource use.
- Prefer native Runwire mechanics over framework-specific wrappers or duplicated policy.
- Prefer fewer transport writes when the full response/frame sequence is already known.
- Do not enable a socket policy globally when protocol evidence shows mixed effects.
- Preserve streaming, backpressure, upgrade, flow-control, and graceful-shutdown semantics.
- Never weaken PHPForge, PHPProbe, Pint, PHPCS, static analysis, or tests to land an optimization.
- No `@` error suppression.
- Any new fast path must have a correctness fallback and explicit tests.
- Short diagnostics identify causes; sustained repeated trials certify production decisions.
- Cross-project behavior is supporting evidence only. Runwire decisions are based on Runwire measurements.

## Master decision tracker

| ID | Finding | Current evidence | Provisional Runwire decision | Library area | Gate | Status |
| --- | --- | --- | --- | --- | --- | --- |
| D01 | H1 split small writes create ~41 ms floor | High | Coalesce bounded non-streaming head + final body into one transport write | `Http1ResponseWriter` | V1 | Validate |
| D02 | TCP_NODELAY removes H1 tiny-write floor | High | Keep/strengthen explicit transport option; do not rely on it as sole H1 fix | listener/socket configuration | V1 | Validate |
| D03 | H1 TLS has same split-write pathology | High | Same H1 coalescing fast path must work for TLS without special API | `Http1ResponseWriter`, `Connection` | V1 | Validate |
| D04 | H2 tiny responses show same write-latency floor | High | Add bounded HEADERS + first DATA/END_STREAM coalescing in scheduler | `ResponseScheduler` | V2 | Validate |
| D05 | H2 global NODELAY can reduce high-multiplex throughput | High | Do not force NODELAY globally for H2 | transport policy | V2 | Validate |
| D06 | H2 one connection saturates one worker | Medium-high | Focus scaling on workers before extra client connection tuning | runtime/worker docs and benchmarks | V5 | Validate |
| D07 | H2 10k stream churn limit ends abruptly | High | Preserve hard bound, investigate graceful pre-limit GOAWAY/drain | H2 connection lifecycle / limits | V6 | Validate |
| D08 | TLS peer close leaks broken-pipe warnings | High | Convert socket-write warnings to scoped transport failure semantics | `Connection` | V7 | Validate |
| D09 | H3 50 ms poll timeout is not active-traffic latency floor | Medium | Keep current default unless sustained V4 disproves it | H3 session/runtime | V4 | Validate |
| D10 | H3 scheduler knobs mostly look noisy | Low-medium | No tuning change without repeated low-CV proof | H3 limits/session | V4 | Validate |
| D11 | H3 16 KiB payload cost becomes visible | Medium | Treat as expected payload/crypto cost unless profiling finds avoidable copies | H3 frame/body path | V4 | Validate |
| D12 | Worker scaling is useful but sublinear | Medium | Document measured scaling; change runtime policy only if V5 finds a code bottleneck | worker/runtime | V5 | Validate |
| D13 | EventLoop vs SelectLoop prior numbers were on different CPUs | High | No backend ranking until same-runner alternating trials finish | `LoopFactory`, docs | V3 | Validate |
| D14 | 2.0 did not regress against matched pre-release baseline / 1.0 comparison evidence | High for tested path | No compatibility rollback; optimize forward | release docs | complete | Keep |
| D15 | Expected write batching is common in peer PHP runtimes | Supporting | Use as design precedent, not as performance proof | H1/H2 design | V1/V2 | Supporting |
| D16 | Graceful H2 GOAWAY exists in mature peers | Supporting | Prefer graceful rotation if Runwire tests prove it preserves hard limits | H2 lifecycle | V6 | Supporting |
| D17 | Scoped write-warning conversion exists in Amp/React | Supporting | Adopt equivalent Runwire-owned mechanism, not their implementation | `Connection` | V7 | Supporting |

## Finding-to-library action map

### D01 — HTTP/1 bounded response coalescing

**Evidence**

Raw TCP reproduced the pathological shape: two small writes with ordinary TCP behavior create the ~41 ms floor; one coalesced write removes it. Runwire's current bounded response path serializes the head in `start()` and sends the final body separately in `end()`.

**Provisional production change**

Add a native one-shot response fast path for responses whose full body is already known when the response is finalized.

Target behavior:

- fixed-length, bounded, non-streaming response: serialize head + body once and call `Connection::write()` once;
- body-suppressed HEAD/204/304 semantics remain correct;
- explicit streaming via `start()` / `write()` remains unchanged;
- chunked streaming remains unchanged;
- WebSocket upgrade remains unchanged;
- backpressure remains owned by `Connection`;
- max response header/chunk/body limits remain enforced before transport write.

**Preferred shape**

Do not add a second public response API merely for performance. Keep the public writer contract and optimize the internal final-response path.

**Tests to add if V1 passes**

- fixed-length `end($body)` produces one transport write;
- large bounded response still respects configured chunk/response limits;
- `start(); write(); end()` does not enter coalescing fast path;
- chunked response remains streamed;
- HEAD / 204 / 304 semantics;
- connection-close response;
- backpressure result propagation;
- WebSocket upgrade unaffected.

**Decision gate**

Promote if coalescing removes the latency floor and does not materially regress 16 KiB/64 KiB, TLS, or high-concurrency throughput.

---

### D02 — TCP_NODELAY as transport policy

**Evidence**

NODELAY eliminates the tiny split-write H1 floor. Workerman, Swoole and Swow commonly enable it; Amp exposes it explicitly but defaults it off. Runwire's own H2 evidence shows global NODELAY can be worse under natural multiplexed batching.

**Provisional production change**

Keep TCP_NODELAY as a deliberate socket option, not a universal protocol default.

Evaluate whether Runwire needs a first-class typed listener option in addition to the existing socket-context mechanism. Do not add one if `ListenerOptions::socketContext` already provides a clear, stable native path and documentation is enough.

**Rules**

- H1 may recommend NODELAY for streaming/tiny-write workloads after coalescing is implemented.
- H2 must not inherit an unconditional NODELAY default from H1.
- H3 is UDP/QUIC; NODELAY is irrelevant.

**Decision gate**

V1/V2 determine whether any default should change. Absent strong evidence, keep the default neutral and document the option.

---

### D03 — HTTP/1 TLS write shape

No separate TLS optimization should be invented. The same bounded-response coalescing path must pass plaintext and TLS tests. If TLS requires a special branch, stop and investigate the transport layer instead of duplicating HTTP logic.

---

### D04 — HTTP/2 HEADERS + first DATA coalescing

**Evidence**

Low-multiplex tiny H2 responses reproduce the ~41 ms floor, but high multiplexing and larger payloads benefit from normal batching. Therefore the target is fewer immediate writes, not blanket NODELAY.

**Provisional production change**

Teach `Http2\Internal\ResponseScheduler` to opportunistically emit the initial response HEADERS block and the first DATA frame in one transport write when all conditions are safe.

Required semantics:

- HPACK/header validation unchanged;
- CONTINUATION rules preserved;
- connection and stream flow control consumed exactly once;
- END_STREAM applied to the correct frame;
- empty response may coalesce HEADERS with END_STREAM on HEADERS where protocol-correct, or otherwise preserve current empty DATA behavior;
- max frame size respected;
- scheduler fairness preserved;
- wire queue / pressure accounting uses combined byte size;
- control frames are never delayed behind response coalescing;
- a stream with previously flushed headers falls back to normal DATA scheduling;
- streaming responses keep normal scheduling.

**Implementation preference**

Avoid a generic "concatenate arbitrary frames" switch. Model a narrow initial-response fast path so scheduler invariants remain obvious.

**Tests if V2 passes**

- HEADERS + single DATA becomes one transport write;
- large body still splits by peer/max frame size;
- HEADERS requiring CONTINUATION remain valid;
- zero-length final body;
- stream and connection flow-control boundaries;
- pressure/rejected-limit behavior;
- multi-stream fairness;
- SETTINGS/control frames unaffected;
- high-multiplex throughput regression guard.

---

### D05 — H2 NODELAY default

**Provisional decision: do not enable globally.**

A global default change is rejected unless V2 shows coalescing + NODELAY consistently wins across tiny and larger payload/multiplex cases. The current evidence already makes that unlikely.

If a future runtime wants NODELAY for a specific deployment, retain explicit transport configurability.

---

### D06 / D12 — worker scaling

After H1/H2 write strategy is finalized:

- benchmark 1/2/4 workers, optionally 8 only when runner CPU capacity supports it;
- measure scaling efficiency, CPU saturation, RSS, accept distribution and socket contention;
- inspect `SO_REUSEPORT`/listener behavior only if scaling flattens before CPU saturation;
- do not automatically set workers = CPU count without evidence.

**Likely library outcome**

Documentation/runtime guidance unless V5 reveals a concrete Runwire bottleneck.

---

### D07 — H2 stream-churn lifecycle

**Current behavior**

The hard `maxStreamsPerConnection = 10_000` resource bound works, but the next stream causes an abrupt protocol error path.

**Candidate production behavior**

Introduce a graceful rotation threshold below the hard ceiling:

1. send GOAWAY with the last accepted client stream ID;
2. mark connection draining;
3. stop accepting new request streams;
4. finish active streams and pending writes;
5. close normally after drain;
6. retain the hard 10,000 bound as an abuse/failsafe ceiling.

The graceful threshold may be derived from the hard limit rather than adding a second user-facing option unless configuration evidence requires it.

**Security rule**

Graceful rotation must never permit more accepted streams than the configured hard resource bound.

**H3**

Run an analogous request-stream lifecycle probe. Mirror the user-visible lifecycle semantics only where HTTP/3/QUIC protocol rules support it naturally.

---

### D08 — transport write warning handling

**Evidence**

Expected TLS disconnects can make `fwrite()` emit `SSL: Broken pipe` warnings even though the correct runtime action is simply to mark the peer write as failed/closed.

**Crossmatch**

Amp and React use scoped error handling around stream writes. Workerman uses `@fwrite`, which Runwire must not copy.

**Provisional production change**

Add one small Runwire-owned write primitive inside `Connection` (or a focused internal helper only if complexity requires it):

- install a temporary error handler immediately around `fwrite()`;
- capture the warning message/errno context;
- restore the prior handler in `finally`;
- return the real `fwrite()` result;
- map expected peer-close/broken-pipe outcomes into existing write failure / `CloseReason::WRITE_ERROR` semantics;
- preserve unexpected conditions through diagnostics/logging as appropriate;
- use the same primitive for immediate writes and buffered `handleWritable()` writes.

**Tests**

TLS peer closes before write, peer closes mid-buffer, plain TCP reset, partial write, EAGAIN-style zero write where applicable, and proof that unrelated PHP warnings are not suppressed.

---

### D09 / D10 / D11 — HTTP/3

**Current production stance**

No H3 tuning change yet.

V4 must use repeated sustained trials and CV. For each candidate:

- if improvement is within noise: keep current default and close finding;
- if lower limits hurt but higher values do not improve beyond noise: keep current default;
- if a higher value repeatedly improves throughput/latency without CPU/RSS/resource regression: implement only that proven change;
- if 16 KiB cost is mostly payload/crypto bandwidth: document, do not "optimize" by weakening correctness or buffering limits.

Potential profiling targets only if V4 justifies them:

- avoidable body copies;
- frame serialization copies;
- pump-loop iteration overhead;
- repeated temporary allocation;
- excessive userland/native boundary crossings.

---

### D13 — EventLoop vs SelectLoop

V3 must alternate both backends on the same GitHub runner and use repeated trials. If EventLoop wins materially:

- keep `LoopFactory::native()` preference for ext-event;
- document measured advantage;
- no SelectLoop degradation or removal.

If difference is negligible:

- retain current preference/fallback design;
- do not add complexity merely to chase backend selection.

If SelectLoop wins a workload:

- investigate why before changing preference; ext-event may still scale better at connection counts beyond select's portable ceiling.

---

## Ecosystem crossmatch

### Workerman

Observed design choices:

- TCP listeners enable `TCP_NODELAY`;
- normal buffered H1 response is encoded as one complete HTTP string;
- smaller file responses may concatenate headers + body before send;
- transport send path owns buffering/backpressure;
- its coroutine package selects Fiber/Swoole/Swow execution drivers and does not own HTTP/TCP packetization policy.

**Runwire takeaway:** strongest precedent for D01 + explicit D02, but not sufficient reason to copy global NODELAY into H2.

### ReactPHP

Observed design choices:

- buffered/non-streaming H1 responses can write headers + body together;
- streaming responses remain incremental;
- stream layer owns buffered writes and scoped warning handling.

**Runwire takeaway:** supports a bounded-response fast path and D08-style scoped transport error handling.

### Amp

Observed design choices:

- socket `BindContext` / `ConnectContext` exposes `withTcpNoDelay()`, default false;
- documentation recognizes Nagle's effect on tiny-response benchmarks;
- HTTP drivers keep streaming and flow-control semantics explicit;
- disconnects surface as stream exceptions;
- H2 graceful shutdown uses GOAWAY and drain semantics.

**Runwire takeaway:** strong precedent for explicit transport policy, D08, and D07.

### Swoole / OpenSwoole / Swow

Observed design choices are implemented largely in native/C transport layers. Swoole and Swow expose/enable NODELAY and Swoole has explicit H2 GOAWAY support.

**Runwire takeaway:** useful confirmation that these knobs matter, but throughput architecture is not directly comparable to Runwire's portable native PHP path.

### HTTP/3 ecosystem result

No sufficiently comparable mature native-PHP H3 server implementation was found in the inspected peers. Runwire H3 decisions remain driven by its own QUIC evidence.

---

## Validation batches

### V1 — H1 real writer strategy

Matrix:

- current split/default TCP;
- split + NODELAY;
- coalesced;
- coalesced + NODELAY;
- plaintext + TLS;
- 2 B, 1 KiB, 16 KiB, 64 KiB;
- concurrency 1/16/64/256.

Short diagnostic runs may prune obviously losing combinations. Final candidate must receive five repeated sustained trials.

**Output:** choose D01/D02 production behavior.

### V2 — H2 real scheduler strategy

Matrix:

- current/default;
- NODELAY;
- initial response coalescing;
- coalescing + NODELAY;
- payload 2 B/1 KiB/16 KiB/64 KiB;
- 1/8/32/100 streams;
- connection scaling only where necessary.

**Output:** choose D04/D05 behavior.

### V3 — same-runner EventLoop vs SelectLoop

- same VM;
- same PHP/extensions/OPcache;
- alternate execution order;
- five trials;
- compare median, CV, p95/p99, CPU/RSS.

**Output:** close D13.

### V4 — sustained H3

- five repeated trials per candidate;
- enough requests/duration to remove ~100 ms noise;
- poll timeout, write budget, stream acceptance, read budget, concurrency and payload;
- reject changes inside variance.

**Output:** close D09–D11.

### V5 — worker scaling

Run after V1/V2 winners exist.

**Output:** D06/D12.

### V6 — graceful churn

Implement benchmark-only H2 and H3 drain variants, verify active-stream completion and hard ceiling.

**Output:** D07.

### V7 — write error semantics

Build transport tests and benchmark-only/prototype scoped write helper.

**Output:** D08.

---

## Production implementation batches

These batches become active only when their gates pass.

### I1 — H1 bounded-response fast path

- implement internal coalescing;
- unit/feature tests;
- plaintext/TLS regression;
- streaming/WebSocket/backpressure verification;
- PHPForge QA;
- commit.

### I2 — H2 initial-response batching

- implement narrow scheduler fast path;
- protocol/frame/flow-control tests;
- fairness/backpressure tests;
- H2 benchmark regression;
- PHPForge QA;
- commit.

### I3 — transport warning normalization

- scoped stream-write warning capture;
- immediate + buffered paths;
- TLS/TCP disconnect tests;
- diagnostics review;
- PHPForge QA;
- commit.

### I4 — graceful connection rotation

- H2 GOAWAY/drain before hard churn ceiling;
- H3 equivalent only if V6 supports it;
- long-lived client tests;
- abuse-bound tests;
- PHPForge QA;
- commit.

### I5 — proven H3 tuning only

Apply only V4 winners. If none exceed noise, mark complete with no code change.

### I6 — worker/runtime scaling fixes only if proven

Apply only concrete V5 bottlenecks. Otherwise publish guidance and keep runtime code unchanged.

### I7 — full protocol regression

- H1 plain/TLS;
- H2 TLS/ALPN;
- H3 QUIC;
- WebSocket;
- streaming/chunking;
- backpressure;
- lifecycle/churn;
- peer disconnects;
- PHP 8.4 + 8.5.

### I8 — release certification

- 30 s warm-up;
- five 180 s trials for final performance-sensitive paths;
- 30-minute soak where applicable;
- CPU/RSS/latency/error evidence;
- PHPForge full release guard;
- no checker bypasses;
- update benchmark docs and migration notes if behavior/config changes.

---

## Final decision record template

For each D-item, update this plan with:

- **Decision:** Implement / Keep / Drop
- **Evidence run:** workflow ID + exact SHA
- **Measured effect:** median delta, CV, latency, CPU/RSS
- **Correctness result:** tests/protocol checks
- **Library change:** exact classes/files
- **Public API/config impact:** none or explicit change
- **Release target:** next patch/minor/major as appropriate

The implementation branch must not be created until D01/D04/D09-D13 have enough evidence to close V1–V4. Once those decisions are final, this playground branch remains the benchmark/evidence archive and production work moves to a clean branch from current `main`.
