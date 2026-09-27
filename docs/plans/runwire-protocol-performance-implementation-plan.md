# Runwire protocol performance implementation decision plan

Updated: 2026-09-27

Reference Branch: `benchmarks/1.0-vs-2.0`

Implementation Branch: `feat/http-improvement`

Status: **production implementation active on `feat/http-improvement`; H1 I1 is complete and fully QA-certified, H2 I2 is active, and playground-only benchmark/prototype files remain isolated on `benchmarks/1.0-vs-2.0`**

Companion evidence tracker: `https://github.com/infocyph/Runwire/blob/benchmarks/1.0-vs-2.0/docs/plans/runwire-protocol-performance-playground.md`

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

## Latest validation snapshot

Primary validation run: GitHub Actions workflow `36300595782` at `070227b07f036d890293c74fd54e25bde34aa2ef`.

The workflow completed all four validation jobs successfully. The green workflow proves correctness of the exercised benchmark variants, but it does **not** by itself promote every prototype: V1 and V2 currently provide one measured sample per matrix cell, while V3 and V4 provide five repeated trials with summary statistics.

Key results:

- **V1 / HTTP/1:** application coalescing clearly removes the delayed-ACK floor for small plaintext and TLS responses. At 2 B / concurrency 16, plaintext moved from **392.207 RPS / 41.40 ms p95** to **6,926.433 RPS / 3.28 ms p95**; TLS moved from **385.522 RPS / 41.16 ms p95** to **5,874.581 RPS / 3.98 ms p95**. However, coalescing alone does **not** remove the floor for 16 KiB TLS at concurrency 1/16/64, where p95 remains about 42 ms; explicit NODELAY removes that low-concurrency floor. The current diagnostic also shows that NODELAY can reduce peak saturated TLS throughput at concurrency 256. V1 therefore has **two** remaining decisions: whether D01 coalescing is safe to promote, and whether H1 should default NODELAY **on** despite the saturation trade-off. Workerman, Swoole and Swow provide supporting precedent for default-on TCP NODELAY, but Runwire will make the H1 policy from its own repeated evidence.
- **V2 / HTTP/2:** broad HEADERS + first-DATA coalescing is a strong tiny-response fix but is not universally safe as currently prototyped. At 2 B / 1 stream, throughput moved from **24.2 RPS / 41.00 ms mean** to **4,192.6 RPS / 0.23 ms mean**; at 2 B / 32 streams, **774.4 → 4,789.0 RPS**. But 1 KiB / 100 streams regressed **5,390.2 → 4,547.6 RPS (-15.6%)**, and 16 KiB / 1 stream still sits at about 41 ms without NODELAY. This rejects an unconditional H2 coalescing patch and narrows D04 to a bounded tiny-response fast path or Drop.
- **V3 / loop backend:** same-runner five-trial results are workload-dependent. SelectLoop leads the 2 B / concurrency 16 case, EventLoop leads the 16 KiB / concurrency 64 case, and several cells have material CV. There is no evidence for a universal backend ranking. Keep the current native preference/fallback design.
- **V4 / HTTP/3:** sustained five-trial results do not show a stable tuning winner. Baseline is **4,030.5 RPS, 2.642% CV**; 1 ms poll is **4,065.833 RPS**, 10 ms poll **4,152.651 RPS**, writes/flush 64 **4,206.267 RPS**, writes/flush 512 **4,146.957 RPS**, streams/pump 128 **4,121.603 RPS**, and concurrency 32 **4,022.529 RPS**. The small non-monotonic deltas overlap run variance and do not justify changing defaults. The 16 KiB payload case falls to **2,649.589 RPS** with only **0.807% CV**, confirming the larger-payload cost is real but not identifying an avoidable scheduler defect.

CPU/RSS is already captured by the H1 and same-runner backend harnesses. Final H2 certification must add equivalent server CPU/RSS evidence before D04 can be promoted; H3 stays unchanged, so no new production tuning requires a resource-cost justification.

## Master decision tracker

| ID | Finding | Current evidence | Runwire decision | Library area | Gate | Status |
| --- | --- | --- | --- | --- | --- | --- |
| D01 | H1 split small writes create ~41 ms floor | High; playground evidence confirms large tiny-response gain | Implicit bounded `end($body)` coalesces head + body; explicit `start/write/end` remains streaming | `Http1ResponseWriter` | complete | **Implement** |
| D02 | TCP_NODELAY removes H1/TLS delayed-ACK floor | High; major latency win plus peer-runtime precedent | H1 defaults NODELAY ON after protocol selection when live socket tuning is available; explicit listener override wins; H2 remains neutral | `NativeHttpConnection`, `Connection`, `TcpListener`, `TcpSocketTuner` | complete | **Implement** |
| D03 | H1 TLS has same transport pathology | High; 16 KiB TLS remains ~42 ms with coalescing alone | Same H1 response path for plaintext/TLS; H1 NODELAY policy handles the transport case without a TLS-specific response API | H1 transport path | complete | **Implement** |
| D04 | H2 tiny responses show same write-latency floor | High; broad prototype fixes tiny cases but regresses 1 KiB / 100 streams | Implement a narrow implicit one-shot fast path bounded by combined pre-encode response size; larger/streaming responses keep the existing scheduler | `Http2ResponseWriter`, `ResponseScheduler` | I2 | **Active** |
| D05 | H2 global NODELAY can reduce high-multiplex throughput | High; reconfirmed by V2 | Do not force NODELAY globally for H2 | transport policy | complete | Keep |
| D06 | H2 one connection saturates one worker | Medium-high | Focus scaling on workers before extra client connection tuning | runtime/worker docs and benchmarks | V5 | Validate |
| D07 | H2 10k stream churn limit ends abruptly | High | Preserve hard bound, investigate graceful pre-limit GOAWAY/drain | H2 connection lifecycle / limits | V6 | Validate |
| D08 | TLS peer close leaks broken-pipe warnings | High | Convert socket-write warnings to scoped transport failure semantics | `Connection` | V7 | Validate |
| D09 | H3 50 ms poll timeout is not active-traffic latency floor | High after sustained V4 | Keep current default | H3 session/runtime | complete | Keep |
| D10 | H3 scheduler knobs do not show a stable sustained winner | High after sustained V4 | Keep current defaults; no tuning code | H3 limits/session | complete | Keep |
| D11 | H3 16 KiB payload cost becomes visible | High after sustained V4 | Keep current behavior; treat as payload/crypto cost unless future profiling proves avoidable copies | H3 frame/body path | complete | Keep |
| D12 | Worker scaling is useful but sublinear | Medium | Document measured scaling; change runtime policy only if V5 finds a code bottleneck | worker/runtime | V5 | Validate |
| D13 | EventLoop vs SelectLoop is workload-dependent on the same runner | High after V3 | Keep current `LoopFactory::native()` preference/fallback; publish no universal backend ranking | `LoopFactory`, docs | complete | Keep |
| D14 | 2.0 did not regress against matched pre-release baseline / 1.0 comparison evidence | High for tested path | No compatibility rollback; optimize forward | release docs | complete | Keep |
| D15 | Expected write batching is common in peer PHP runtimes | Supporting | Use as design precedent, not as performance proof | H1/H2 design | V1/V2 | Supporting |
| D16 | Graceful H2 GOAWAY exists in mature peers | Supporting | Prefer graceful rotation if Runwire tests prove it preserves hard limits | H2 lifecycle | V6 | Supporting |
| D17 | Scoped write-warning conversion exists in Amp/React | Supporting | Adopt equivalent Runwire-owned mechanism, not their implementation | `Connection` | V7 | Supporting |
| D18 | H3 beginDrain can write GOAWAY after peer QUIC shutdown and throw | High | Make H3 drain-after-peer-close idempotent/non-fatal while preserving real protocol errors | `PhpQuicHttp3Worker`, `PhpQuicHttp3Connection` | V6 | Validate |

## Finding-to-library action map

### D01 — HTTP/1 bounded response coalescing

**Evidence**

Raw TCP reproduced the pathological shape: two small writes with ordinary TCP behavior create the ~41 ms floor; one coalesced write removes it. Runwire's current bounded response path serializes the head in `start()` and sends the final body separately in `end()`.

**Candidate production change**

Add a native one-shot response fast path for responses whose full body is already known when the response is finalized.

The first V1 prototype is directionally successful but not yet promotable. It removes the floor for small plaintext/TLS responses and for 16 KiB plaintext, while 16 KiB TLS remains on the ~42 ms plateau without NODELAY. One high-concurrency TLS cell also moved unfavorably in the single-sample diagnostic. Treat those as certification blockers, not reasons to discard coalescing.

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

Promote D01 if five repeated trials show that bounded coalescing materially improves the tiny-response path while staying within the regression budget for 1 KiB/16 KiB/64 KiB, TLS, and high concurrency. Coalescing is **not** required to eliminate the TLS 16 KiB record-boundary floor by itself; that behavior is covered by D02/D03 transport guidance. Drop the fast path if the repeated high-concurrency cost is material.

---

### D02 — TCP_NODELAY as H1 transport policy

**Evidence**

NODELAY eliminates the H1/TLS delayed-ACK floor in the cases where application coalescing is insufficient. The current diagnostic shows a large latency/throughput win at low and moderate concurrency, while some saturated TLS concurrency-256 cells lose aggregate throughput.

Peer precedent is meaningful but not decisive:

- Workerman enables `TCP_NODELAY` on accepted TCP sockets;
- Swoole server ports default `open_tcp_nodelay` to true;
- Swow/libcat states that TCP is nodelay by default;
- Amp exposes explicit NODELAY but leaves it opt-in;
- ReactPHP does not force it globally.

That ecosystem split makes this a Runwire policy decision rather than a convention to copy.

**Candidate production policy**

V1 must choose between:

1. **H1 default ON** — enable NODELAY for accepted connections that are actually serving HTTP/1, while still allowing explicit override; or
2. **H1 neutral default** — preserve current socket behavior and document NODELAY as the recommended latency-sensitive option.

Do **not** make NODELAY a universal listener/TCP default merely because H1 benefits. H2 remains a separate policy and D05 already rejects unconditional NODELAY there.

For shared TLS listeners, prefer applying the H1 choice after protocol/ALPN selection where the accepted connection can be treated according to the negotiated protocol. Do not create a design in which enabling the H1 default silently forces the same policy onto H2.

**V1 decision criteria**

Promote **H1 default ON** only if five repeated trials show all of the following:

- the low/moderate-concurrency latency benefit remains large and stable for plaintext and TLS;
- median throughput improvement is material for the target H1 workloads;
- any saturated concurrency-256 throughput loss is understood and remains within the accepted regression budget;
- CPU/RSS cost is acceptable;
- coalescing + NODELAY interaction does not introduce correctness, backpressure, streaming, or WebSocket regressions.

If the saturated-throughput penalty is material enough to outweigh the latency benefit, keep the default neutral and retain explicit opt-in.

**API/configuration rule**

Do not add a new public option solely for this decision if the existing socket-context mechanism and an internal protocol-specific accepted-connection policy are sufficient. Add configuration only if users need a stable way to override the H1 default independently from H2.

---

### D03 — HTTP/1 TLS write shape

The original assumption that application-level coalescing would remove the floor for every TLS payload is disproven by the current V1 diagnostic. At 16 KiB, one coalesced application write still exhibits the ~42 ms low-concurrency plateau, while NODELAY removes it. That result is consistent with transport/TLS record segmentation creating a small tail write, but the plan does not require a new TLS-specific response API to work around it.

Production rule:

- D01 must remain protocol-level and identical for plaintext/TLS;
- D02 decides whether H1 itself defaults NODELAY on; do not hard-code the answer here;
- document the measured TLS record-boundary behavior regardless of the D02 outcome;
- do not toggle NODELAY per response;
- do not add TLS-specific response buffering unless a lower-layer profile proves a generally useful transport fix.

---

### D04 — HTTP/2 HEADERS + first DATA coalescing

**Evidence**

Low-multiplex tiny H2 responses reproduce the ~41 ms floor, but high multiplexing and larger payloads benefit from normal batching. Therefore the target is fewer immediate writes, not blanket NODELAY.

**Candidate production change**

Do **not** promote the current broad benchmark patch as-is. It fixes tiny responses dramatically, but the 1 KiB / 100-stream case regresses by 15.6% and 16 KiB / 1 stream still hits the TLS floor.

V2 now has a binary target:

- validate a **bounded tiny-response fast path** that coalesces initial HEADERS + first DATA only when the combined wire payload and scheduler state make it safe; or
- **Drop** D04 if avoiding the high-multiplex regression requires broad scheduler complexity or workload-specific tuning.

Any production path must fall back to the existing scheduler before larger payloads or natural multiplex batching become the better strategy.

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

**Final decision: do not enable globally.**

V2 reconfirms that NODELAY helps low-stream tiny responses but can materially reduce throughput once H2 natural batching is effective. D05 is closed as **Keep**: preserve the current neutral transport default and explicit opt-in capability.

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

**Final decision: keep current production defaults.**

V4 completed five repeated sustained trials per candidate at run `36300595782`. The baseline was 4,030.5 RPS with 2.642% CV. Poll, write-budget, stream-acceptance, and concurrency variants moved non-monotonically by roughly -0.2% to +4.4%, with candidate CVs up to 2.768%. That is not a stable enough signal to justify runtime tuning.

The 16 KiB payload result, 2,649.589 RPS with 0.807% CV, is repeatable evidence that larger-payload/crypto cost becomes visible. It does not identify a correctness-preserving scheduler knob that improves the path.

Close D09-D11 as **Keep**. Future profiling may still inspect avoidable copies, frame serialization, temporary allocation, or userland/native crossings, but those are new findings rather than unfinished work in this plan.

---

### D13 — EventLoop vs SelectLoop

**Final decision: keep the current backend policy.**

V3 completed alternating same-runner five-trial comparisons. Results are workload-dependent rather than a universal backend ranking: SelectLoop leads one tiny-response cell, EventLoop leads one larger/high-concurrency cell, and several cells carry enough CV that a default flip would be overfitting.

Keep `LoopFactory::native()` preferring ext-event when available and SelectLoop as the portable fallback. Do not degrade or remove either backend, and do not publish a blanket performance ranking from this evidence.

---

### D18 — H3 drain after peer-close

**Evidence**

The first sustained V4 run served the complete request set, the client completed all streams and shut down QUIC, and the server then entered graceful worker drain. `beginDrain()` appended GOAWAY and immediately flushed the local control stream; ext-quic rejected the write because the QUIC protocol was already shutdown, producing an uncaught fatal exception.

**Provisional production change**

Do not treat an already-closed peer as a protocol error during local graceful shutdown.

Candidate behavior:

- before beginning drain, recognize connections already observed closed and skip GOAWAY;
- if a control-stream write reports native transport shutdown while drain is starting, mark the connection closed/released rather than terminating the worker;
- do not swallow unrelated QUIC exceptions;
- preserve GOAWAY for live connections;
- keep `forceClose()` semantics bounded and idempotent.

**Tests**

- peer closes before worker `stopAccepting()`;
- peer closes while GOAWAY is pending;
- live peer receives GOAWAY and drains normally;
- native transport write error unrelated to peer shutdown remains visible;
- repeated `stopAccepting()` / `forceClose()` is safe.

**Decision gate**

V6 must reproduce both live-peer graceful drain and already-closed-peer drain. If the distinction can be made reliably, implement; otherwise drop any broad exception swallowing and use a narrower lifecycle fix.

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

### V1 — H1 writer and NODELAY policy

**Status: reference-branch evidence accepted for production implementation.** The playground remains the evidence archive; its patch scripts, synthetic harnesses, and validation workflows are not copied into `feat/http-improvement`. Production acceptance on this branch is based on source semantics, protocol regression, PHPForge QA, and the repository's normal benchmark/release gates.

The broad single-sample matrix established the behavior of default, NODELAY, coalesced, and combined modes. V1 now has two explicit production decisions: D01 bounded response coalescing and D02 H1 default NODELAY policy. D03 closes from the same TLS evidence.

Final certification:

- current production path;
- current path + NODELAY;
- bounded coalescing candidate;
- bounded coalescing + NODELAY;
- plaintext + TLS;
- 2 B, 1 KiB, 16 KiB, 64 KiB;
- concurrency 16/64/256, plus concurrency 1 for TLS record-boundary confirmation;
- five alternating repeated trials per retained comparison;
- median RPS, CV, p95/p99, CPU and RSS;
- correctness, streaming, backpressure, WebSocket, and body-semantics gates.

Decision analysis must report latency and throughput separately. Do not hide a large latency improvement behind peak-throughput averages, and do not hide a material saturation regression behind low-concurrency wins.

**Output:**

- D01 → Implement or Drop bounded H1 coalescing;
- D02 → H1 NODELAY default ON or neutral;
- D03 → close with measured TLS behavior and no TLS-specific response API.

### V2 — H2 real scheduler strategy

**Status: broad prototype diagnostic complete; unconditional coalescing rejected.**

D05 is closed. V2 now tests only a bounded tiny-response candidate against the current scheduler.

Final certification:

- current production scheduler vs bounded tiny-response candidate;
- payload 2 B/1 KiB/16 KiB/64 KiB;
- 1/8/32/100 streams;
- five alternating repeated trials;
- median RPS, CV, request latency, server CPU and RSS;
- correctness, frame-size, CONTINUATION, flow-control, pressure, fairness, and control-frame gates;
- explicit regression guard for the 1 KiB / 100-stream and larger-payload cases.

**Output:** Implement or Drop D04.

### V3 — same-runner EventLoop vs SelectLoop

**Status: complete** at workflow `36300595782`, SHA `070227b07f036d890293c74fd54e25bde34aa2ef`.

Five alternating trials on the same runner show workload-dependent results, not a universal winner.

**Output:** D13 closed as **Keep**.

### V4 — sustained H3

**Status: complete** at workflow `36300595782`, SHA `070227b07f036d890293c74fd54e25bde34aa2ef`.

Five 25,000-request trials per candidate show no stable tuning winner beyond variance/noise. The larger-payload cost is repeatable, but no safe scheduler knob removes it.

**Output:** D09-D11 closed as **Keep**; I5 requires no code change.

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

**Status: complete.** Full repository QA passed, including PHPForge analysis/QA on PHP 8.4 and 8.5, protocol-core benchmarks, portable native, Swoole/OpenSwoole, source audit, and HTTP/3/QUIC compatibility at workflow run `36310162888`.

- implicit bounded `end($body)` coalesces head + body without delaying explicit `start()`;
- H1 defaults NODELAY on after protocol selection where ext-sockets permits live accepted-socket tuning;
- explicit listener `tcp_nodelay` configuration takes precedence;
- H2 transport policy remains neutral;
- focused unit/feature coverage added for one-shot semantics, HEAD behavior, fallback, H1/H2 NODELAY separation, and explicit override;
- plaintext/TLS, streaming/WebSocket/backpressure and PHPForge gates remain the acceptance step.

### I2 — H2 initial-response batching

**Status: active.**

- add an implicit one-shot response callback; explicit `start()/write()` remains on the existing scheduler path;
- coalesce only when a conservative pre-encode bound keeps HEADERS + final DATA below 1 KiB of combined wire intent;
- the measured 1 KiB body case therefore falls back to the existing scheduler instead of entering the fast path;
- preserve HPACK state, CONTINUATION fallback, flow control, frame-size limits, pressure accounting, control-frame priority, and stream fairness;
- add focused fast-path/fallback tests and run full PHPForge QA.

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

### I5 — H3 tuning

**Complete with no code change.** V4 produced no winner strong enough to justify changing H3 defaults.

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

`feat/http-improvement` is the clean production implementation branch from current `main`. Keep `benchmarks/1.0-vs-2.0` as the benchmark/evidence archive; do not migrate playground-only harnesses, patch scripts, or validation workflows into this branch.

V5-V7 remain independent post-selection gates: they may prototype on the playground branch, but I3/I4/I6 production changes must not land without their own evidence records. No unresolved item is silently deferred: each remaining D-item must end as Implement, Keep, or Drop before release certification.
