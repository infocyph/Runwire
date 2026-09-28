# Runwire protocol performance implementation decision plan

Updated: 2026-09-27

Reference Branch: `benchmarks/1.0-vs-2.0`

Implementation Branch: `feat/http-improvement`

Status: **runtime hardening is implemented; H1 defaults to FIXED and H2/H3 to AUTO by the release decision; J10 certification requires the full sustained matrix, and I8 remains reserved for external release certification**

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
| D04 | H2 tiny responses show same write-latency floor | High; broad prototype fixed tiny cases but regressed 1 KiB / 100 streams | Narrow implicit one-shot fast path is limited to <1 KiB combined wire intent; 1 KiB/larger and streaming responses retain the existing scheduler | `Http2ResponseWriter`, `ResponseScheduler` | complete | **Implement** |
| D05 | H2 global NODELAY can reduce high-multiplex throughput | High; reconfirmed by V2 | Do not force NODELAY globally for H2 | transport policy | complete | Keep |
| D06 | H2 one connection saturates one worker | Medium-high; adding 4/16 client connections did not materially improve one-worker throughput | Keep runtime policy; scale measured worker capacity rather than multiplying client connections | deployment guidance | complete | **Keep** |
| D07 | H2 10k stream churn limit ends abruptly | High; lifecycle implementation and abuse-bound tests complete | Preserve the exact hard bound and begin graceful GOAWAY rotation when the final allowed stream is admitted | `Http2Connection`, `RequestStreamProcessor` | complete | **Implement** |
| D08 | TLS/TCP peer-close writes can leak `fwrite()` warnings | High; scoped helper passes tests and benchmark gates | Route immediate and buffered writes through a scoped `fwrite()` helper; warnings map to existing `WRITE_ERROR` semantics and caller warning handling is restored | `Connection`, `StreamWriter` | complete | **Implement** |
| D09 | H3 50 ms poll timeout is not active-traffic latency floor | High after sustained V4 | Keep current default | H3 session/runtime | complete | Keep |
| D10 | H3 scheduler knobs do not show a stable sustained winner | High after sustained V4 | Keep current defaults; no tuning code | H3 limits/session | complete | Keep |
| D11 | H3 16 KiB payload cost becomes visible | High after sustained V4 | Keep current behavior; treat as payload/crypto cost unless future profiling proves avoidable copies | H3 frame/body path | complete | Keep |
| D12 | Worker scaling is useful but sublinear | Medium; 1→2 ≈1.43× and 1→4 ≈2.58× in the short diagnostic, with no isolated runtime defect | Keep automatic/explicit worker policy and document benchmark-first sizing; no runtime tuning code | deployment guidance | complete | **Keep** |
| D13 | EventLoop vs SelectLoop is workload-dependent on the same runner | High after V3 | Keep current `LoopFactory::native()` preference/fallback; publish no universal backend ranking | `LoopFactory`, docs | complete | Keep |
| D14 | 2.0 did not regress against matched pre-release baseline / 1.0 comparison evidence | High for tested path | No compatibility rollback; optimize forward | release docs | complete | Keep |
| D15 | Expected write batching is common in peer PHP runtimes | Supporting | Use as design precedent, not as performance proof | H1/H2 design | V1/V2 | Supporting |
| D16 | Graceful H2 GOAWAY exists in mature peers | Supporting | Prefer graceful rotation if Runwire tests prove it preserves hard limits | H2 lifecycle | V6 | Supporting |
| D17 | Scoped write-warning conversion exists in Amp/React | Supporting | Adopt equivalent Runwire-owned mechanism, not their implementation | `Connection` | V7 | Supporting |
| D18 | H3 beginDrain can write GOAWAY after peer QUIC shutdown and throw | High; live/closed drain behavior and QUIC lanes pass | Detect native QUIC close state before/after drain flush; observed peer-close becomes idempotent local closure while unrelated write failures remain visible | `PhpQuicConnection`, `PhpQuicHttp3Connection` | complete | **Implement** |

## Production batch tracker

| Batch | Scope | Status | Evidence / outcome |
| --- | --- | --- | --- |
| I1 | H1 bounded response + H1 NODELAY policy | **Complete** | QA run `36310162888` |
| I2 | H2 bounded initial-response batching | **Complete** | Security & Standards `36311109543` |
| I3 | Scoped transport write-warning normalization | **Complete** | Security & Standards `36311729244` |
| I4 | H2 graceful churn + H3 peer-close drain | **Complete** | Full QA after lifecycle fixes; static-analysis follow-up passed |
| I5 | H3 tuning | **Complete / no code** | V4 found no stable tuning winner |
| I6 | Worker/runtime scaling | **Complete / docs only** | Existing evidence supports benchmark-first sizing, not runtime tuning |
| I7 | Full protocol regression | **Complete** | Final full regression at `c4a479fcf76c0c14e1769f1dacef2209d2e9fa42`; Security & Standards `36323729640` plus benchmark/portable/Swoole/source-audit lanes green |
| I8 | Release certification | **Paused** | `release-certification` label removed while J implementation changes the candidate head; rerun on the final candidate; J10 evidence remains separately tracked |

## Finding-to-library action map

### D01 — HTTP/1 bounded response coalescing

**Evidence**

Raw TCP reproduced the pathological shape: two small writes with ordinary TCP behavior create the ~41 ms floor; one coalesced write removes it. Runwire's current bounded response path serializes the head in `start()` and sends the final body separately in `end()`.

**Final decision: Implement.**

Runwire now uses a native one-shot fast path for implicit bounded responses whose full body is known at `end($body)`. Explicit streaming keeps the existing semantics. The playground evidence established the root cause; production QA verified the bounded implementation, fallbacks, HEAD/body semantics, backpressure, WebSocket isolation, and PHPForge limits.

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

**Production coverage**

- fixed-length `end($body)` produces one transport write;
- large bounded response still respects configured chunk/response limits;
- `start(); write(); end()` does not enter coalescing fast path;
- chunked response remains streamed;
- HEAD / 204 / 304 semantics;
- connection-close response;
- backpressure result propagation;
- WebSocket upgrade unaffected.

**Decision record**

D01 is closed as **Implement**. Coalescing addresses the application write shape; D02 owns the remaining H1/TLS transport behavior. The fast path is capacity-bounded and falls back to the existing response path rather than forcing large responses through one write.

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

**Final decision: H1 default ON after protocol selection.**

Runwire enables NODELAY only after an accepted connection is known to be HTTP/1 and only where live accepted-socket tuning is available. An explicit listener `tcp_nodelay` setting remains authoritative. H2 remains neutral and does not inherit the H1 choice.

For shared TLS listeners, prefer applying the H1 choice after protocol/ALPN selection where the accepted connection can be treated according to the negotiated protocol. Do not create a design in which enabling the H1 default silently forces the same policy onto H2.

**Decision record**

D02 is closed as **Implement**. The policy deliberately optimizes H1 latency without changing H2 transport defaults. The implementation preserves user override capability and keeps protocol selection as the ownership boundary.

**API/configuration rule**

Do not add a new public option solely for this decision if the existing socket-context mechanism and an internal protocol-specific accepted-connection policy are sufficient. Add configuration only if users need a stable way to override the H1 default independently from H2.

---

### D03 — HTTP/1 TLS write shape

The original assumption that application-level coalescing would remove the floor for every TLS payload is disproven by the current V1 diagnostic. At 16 KiB, one coalesced application write still exhibits the ~42 ms low-concurrency plateau, while NODELAY removes it. That result is consistent with transport/TLS record segmentation creating a small tail write, but the plan does not require a new TLS-specific response API to work around it.

Production rule:

- D01 must remain protocol-level and identical for plaintext/TLS;
- D02 is resolved: H1 defaults NODELAY on after protocol selection while explicit listener override remains authoritative;
- document the measured TLS record-boundary behavior regardless of the D02 outcome;
- do not toggle NODELAY per response;
- do not add TLS-specific response buffering unless a lower-layer profile proves a generally useful transport fix.

---

### D04 — HTTP/2 HEADERS + first DATA coalescing

**Evidence**

Low-multiplex tiny H2 responses reproduce the ~41 ms floor, but high multiplexing and larger payloads benefit from normal batching. Therefore the target is fewer immediate writes, not blanket NODELAY.

**Final decision: Implement a narrow bounded fast path.**

The broad benchmark patch was rejected. Production coalescing is restricted to an implicit initial response below 1 KiB combined wire intent and only when scheduler/flow-control state is immediately safe. The measured 1 KiB body and larger/streaming paths fall back to the existing scheduler, retaining natural H2 batching and multiplex fairness.

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

**Production coverage**

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

**Final decision: keep current runtime policy; publish benchmark-first guidance.**

The playground shows real but sublinear native worker scaling: about 1.43× at two workers and 2.58× at four workers in the short H1 diagnostic. Separately, H2 connection-scaling evidence shows that one multiplexed connection already reaches the one-worker throughput class and that adding 4/16 client connections does not create useful headroom.

No concrete Runwire scheduler, accept, or reuse-port defect was isolated strongly enough to justify runtime tuning. Automatic sizing therefore remains a starting point rather than a performance guarantee, explicit worker counts remain supported, and deployment guidance now tells operators to benchmark 1/2/4 workers with CPU, RSS, latency, accept distribution and contention evidence.

Close D06/D12 as **Keep**. Any future scaling code requires a new reproducible bottleneck rather than extrapolating from sublinear scaling alone.

---

### D07 — H2 stream-churn lifecycle

**Final decision: Implement graceful rotation at the configured hard ceiling.**

The final allowed request stream is admitted, then Runwire sends GOAWAY with that last accepted client stream ID and enters draining state. New streams are refused while active streams and pending writes finish. The configured lifetime-stream ceiling remains exact; graceful behavior does not expand the abuse/resource bound.

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

**Final decision: Implement one scoped transport writer.**

Runwire now routes immediate and buffered stream writes through an internal `StreamWriter`:

- install a temporary error handler immediately around `fwrite()`;
- capture the warning message/errno context;
- restore the prior handler in `finally`;
- return the real `fwrite()` result;
- map expected peer-close/broken-pipe outcomes into existing write failure / `CloseReason::WRITE_ERROR` semantics;
- preserve unexpected conditions through diagnostics/logging as appropriate;
- use the same primitive for immediate writes and buffered `handleWritable()` writes.

**Production coverage**

Focused tests cover immediate and buffered peer-close failures plus restoration of the caller's error handler. Existing connection/write tests and protocol regression lanes cover partial/queued write behavior without suppressing unrelated PHP warnings.

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

**Final decision: Implement state-aware H3 drain.**

An already-observed QUIC peer close is normal during local graceful shutdown and is handled as idempotent closure. Live connections still receive GOAWAY. Production behavior:

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

**Decision record**

D18 is closed as **Implement**. The native close observation is treated as stateful, the drain path rechecks it around GOAWAY flushing, and unrelated QUIC exceptions remain visible.

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

**Status: complete.**

Reference-branch diagnostics identified the split-write/delayed-ACK pathology and the TLS record-boundary case. Production implementation then closed the decision with bounded implicit response coalescing plus H1-only NODELAY after protocol selection. Full repository regression passed without changing explicit streaming, WebSocket, backpressure, or H2 transport policy.

**Output:** D01-D03 closed as **Implement**.

### V2 — H2 real scheduler strategy

**Status: complete.**

The broad prototype was rejected because it regressed the 1 KiB / 100-stream path. Production uses a conservative <1 KiB combined-wire-intent fast path and retains the existing scheduler for larger, pressured, queued, continuation, or streaming cases. Repository QA and protocol benchmarks pass with H2 NODELAY still neutral.

**Output:** D04 closed as **Implement**; D05 remains **Keep**.

### V3 — same-runner EventLoop vs SelectLoop

**Status: complete** at workflow `36300595782`, SHA `070227b07f036d890293c74fd54e25bde34aa2ef`.

Five alternating trials on the same runner show workload-dependent results, not a universal winner.

**Output:** D13 closed as **Keep**.

### V4 — sustained H3

**Status: complete** at workflow `36300595782`, SHA `070227b07f036d890293c74fd54e25bde34aa2ef`.

Five 25,000-request trials per candidate show no stable tuning winner beyond variance/noise. The larger-payload cost is repeatable, but no safe scheduler knob removes it.

**Output:** D09-D11 closed as **Keep**; I5 requires no code change.

### V5 — worker scaling

**Status: closed by evidence synthesis with no runtime code change.**

P5/F04 proves useful but sublinear 1/2/4-worker scaling; P7/F06 proves extra H2 client connections do not materially raise one-worker throughput. Post-I1/I2 repository benchmark gates remain green and no concrete worker/runtime defect has been isolated.

**Output:** D06/D12 closed as **Keep** with deployment guidance.

### V6 — graceful churn

**Status: complete in production implementation.**

H2 rotates with GOAWAY at the final configured lifetime-stream slot while preserving the hard ceiling; beyond-boundary streams are refused. H3 distinguishes live drain from an already-observed QUIC peer close and keeps unrelated transport failures visible.

**Output:** D07 and D18 closed as **Implement**.

### V7 — write error semantics

**Status: complete.**

Scoped `fwrite()` warning handling is implemented in the internal transport writer and used by both immediate and buffered connection writes. QA confirms existing write-failure semantics, no `@` suppression, and restoration of caller warning handling.

**Output:** D08 closed as **Implement**.

---

## Production implementation batches

All implementation batches below were gated by the evidence and QA recorded above.

### I1 — H1 bounded-response fast path

**Status: complete.** Full repository QA passed, including PHPForge analysis/QA on PHP 8.4 and 8.5, protocol-core benchmarks, portable native, Swoole/OpenSwoole, source audit, and HTTP/3/QUIC compatibility at workflow run `36310162888`.

- implicit bounded `end($body)` coalesces head + body without delaying explicit `start()`;
- H1 defaults NODELAY on after protocol selection where ext-sockets permits live accepted-socket tuning;
- explicit listener `tcp_nodelay` configuration takes precedence;
- H2 transport policy remains neutral;
- focused unit/feature coverage added for one-shot semantics, HEAD behavior, fallback, H1/H2 NODELAY separation, and explicit override;
- plaintext/TLS, streaming/WebSocket/backpressure and PHPForge acceptance gates passed.

### I2 — H2 initial-response batching

**Status: complete.** Full repository QA passed at SHA `1663666a6186f8046725f92134022ab5387373f7`, including PHPForge analysis/QA on PHP 8.4 and 8.5, prefer-lowest/stable dependency matrices, protocol-core benchmarks, portable native, Swoole/OpenSwoole, source audit, and HTTP/3/QUIC compatibility. Security & Standards run: `36311109543`.

- implicit one-shot response callback leaves explicit `start()/write()` on the existing scheduler path;
- conservative pre-encode bound admits only responses below 1 KiB combined wire intent;
- the measured 1 KiB body path falls back to the existing scheduler, avoiding the broad-prototype high-multiplex regression;
- HPACK state, CONTINUATION fallback, flow control, frame-size limits, pressure accounting, control-frame priority, and stream fairness are preserved;
- focused tests cover empty END_STREAM-on-HEADERS behavior and the 1 KiB fallback path;
- PHPForge cognitive-complexity and ordered-class-element gates remain intact.

### I3 — transport warning normalization

**Status: complete.** Full QA passed at SHA `28a7e59942c0ada64c026ee71f7d99ff5112d1d8`; Security & Standards run `36311729244`.

- one internal `StreamWriter` scopes only `fwrite()` warnings without `@`;
- immediate and buffered `Connection` writes use the same primitive;
- peer-close failures map into existing `CloseReason::WRITE_ERROR` semantics;
- focused tests prove warning containment, buffered-write failure handling, and restoration of the caller error handler;
- protocol-core and PHPForge benchmark gates pass on PHP 8.4 and 8.5;
- PHPForge analysis/QA, prefer-lowest/stable, QUIC, portable native, Swoole/OpenSwoole and source audit all pass.

### I4 — graceful connection rotation

**Status: complete.**

- H2 begins GOAWAY/drain when the final configured stream-churn slot is admitted, preserving the hard ceiling exactly;
- streams beyond the boundary are refused by draining semantics instead of discovering the limit through an abrupt connection failure;
- H3 drain recognizes an already-observed native QUIC close before writing GOAWAY and re-checks close state if the drain flush fails;
- unrelated H3 transport exceptions remain visible;
- lifecycle/idempotency and abuse-bound tests cover the new semantics;
- PHPForge QA/analyzers, protocol benchmarks, portable native, Swoole/OpenSwoole, and HTTP/3 QUIC lanes pass.

### I5 — H3 tuning

**Complete with no code change.** V4 produced no winner strong enough to justify changing H3 defaults.

### I6 — worker/runtime scaling

**Status: complete with documentation only.**

No concrete V5-era runtime bottleneck is proven. Keep current worker/runtime behavior and publish benchmark-first sizing guidance in `docs/deployment.md`; no speculative worker-count, accept-loop, or reuse-port tuning is added.

### I7 — full protocol regression

**Status: complete.** Final regression head `c4a479fcf76c0c14e1769f1dacef2209d2e9fa42` passed Release Candidate, Source Audit, Portable Native, Swoole/OpenSwoole Coroutine, Benchmarks, and Security & Standards `36323729640`.

Coverage includes H1 plain/TLS, H2 TLS/ALPN, H3 QUIC, WebSocket, streaming/chunking, backpressure, lifecycle/churn, peer disconnects, and PHP 8.4/8.5.

### I8 — release certification

**Status: paused.** The opt-in `release-certification` label was removed before J implementation so long release workers do not certify a moving candidate. Rerun I8 on the final release candidate; full adaptive performance certification independently requires J10.

Required final gate:

- 30 s warm-up;
- five 180 s trials for final performance-sensitive paths;
- 30-minute soak;
- CPU/RSS/latency/error evidence;
- PHPForge full release guard;
- representative Infbyte consumer gate;
- no checker bypasses;
- benchmark/deployment/migration documentation updated for the changed behavior.

---

## J — Adaptive HTTP/1.1, HTTP/2, and HTTP/3 tuning

**Status: implementation complete through J09; J10 default promotion is not certified. HTTP/1.1 uses FIXED; HTTP/2 and HTTP/3 use AUTO by the release decision. Every policy remains explicitly selectable.**

The completed protocol work shows that several performance choices are workload-dependent rather than globally optimal. HTTP/1.1 NODELAY/coalescing, HTTP/2 initial-response batching, loop/backend behavior, and HTTP/3 pump/write/accept budgets all change their relative value as active work and transport pressure increase.

The next investigation therefore must not search for one larger static default. It must determine whether Runwire can preserve its hard safety limits while selecting a better **execution strategy for the current protocol load state**.

Throughout this section, "H1" means the native HTTP/1.1 path.

### J tracker

| ID | Scope | Question | Initial direction | Status |
| --- | --- | --- | --- | --- |
| J01 | Common load model | Which runtime signals predict the crossover between latency-biased and throughput-biased behavior? | Normalized pressure/backlog/activity sample with backlog weighted above active counts | **Complete** |
| J02 | Adaptive state machine | Can LOW / NORMAL / HIGH load states switch safely without oscillation? | Shared EWMA + low/high hysteresis + sustained-sample dwell controller | **Complete** |
| J03 | HTTP/1.1 | Can H1 improve saturated throughput without giving back normal-load latency? | Worker-scoped controller chooses NODELAY default only for newly attached H1.1 connections from write pressure, queued bytes and active connection load | **Complete** |
| J04 | HTTP/2 | Can the tiny-response fast path incorporate multiplex/pressure state and outperform the current <1 KiB static gate? | Adaptive 1024/512/256-byte one-shot budget from active streams, queued bytes and transport pressure; 1 KiB remains the hard ceiling | **Complete** |
| J05 | HTTP/3 pump budgets | Can writes/reads/accept budgets adapt to backlog direction and active-stream pressure? | Independent outbound write adaptation plus inbound ready/active stream read/accept adaptation, always capped by `Http3Limits` | **Complete** |
| J06 | HTTP/3 polling | Should poll timeout vary with idle/busy state? | Preserve readiness-driven active timeout; lengthen idle-listener wait and shorten pending-handshake wait | **Complete** |
| J07 | User policy | Should users be able to select AUTO / LATENCY / THROUGHPUT / FIXED and override crossover thresholds? | Protocol-local `AdaptiveProtocolPolicy` exposes mode, low/high basis-point watermarks, dwell samples and EWMA ratio without changing hard limits | **Complete** |
| J08 | Transition behavior | Does adaptive mode remain stable under bursty and oscillating load? | Protocol transition/oscillation tests across H1.1/H2/H3 using explicit dwell and crossover policies | **Complete** |
| J09 | Resource/correctness guard | Can adaptation remain completely below existing hard protocol/resource limits? | Cross-mode invariant tests prove adaptive choices remain at/below configured hard limits and FIXED preserves static behavior | **Complete** |
| J10 | Promotion | Does adaptive mode beat or equal static defaults across representative workloads without CPU/RSS/fairness regression? | Run the complete matrix below; H1 retains FIXED and H2/H3 retain AUTO by the release decision; short diagnostics do not certify the matrix | **Awaiting external evidence** |

### J01 — common adaptive load model

**Status: complete.** QA-certified at Security & Standards run `36326402615`. `AdaptiveLoadSample` normalizes existing pressure/backlog/activity counters without new high-frequency telemetry, and backlog receives higher score weight than active counts.

Do **not** equate benchmark client concurrency with a server-side runtime signal. A client may report concurrency 128 while many requests are idle; a connection with only a few active streams may still be heavily pressured by queued bytes.

Signal priority should be:

1. **actual transport/backpressure state**;
2. **queued work / pending bytes**;
3. **active work** such as streams or connections;
4. **recent admission/request rate** only when needed as a secondary signal.

Candidate normalized inputs:

- transport pressured / writable state;
- pending transport bytes;
- protocol scheduler queue depth;
- pending response bytes;
- active request streams;
- active connections;
- recent stream/request admissions;
- ready-stream/readable-stream count;
- HPACK/QPACK backlog where relevant.

No adaptive decision may depend on wall-clock client concurrency that Runwire cannot observe directly.

### J02 — adaptive state machine and hysteresis

**Status: complete.** `AdaptiveLoadController` uses integer EWMA smoothing, low/high watermarks, and sustained-sample dwell; isolated spikes cannot change state.

The implemented model is deliberately small:

```text
LATENCY / LOW LOAD
BALANCED / NORMAL LOAD
THROUGHPUT / HIGH LOAD
```

A single threshold is rejected because it can flap around the crossover point.

Preferred model:

```text
LOW -> HIGH        only above high watermark
HIGH -> LOW        only below low watermark
NORMAL             owns the hysteresis band
```

The state transition layer should also evaluate one of:

- minimum dwell time before another state change;
- EWMA / rolling load score;
- N consecutive samples above/below a boundary.

Exact mechanics remain open until transition benchmarks identify the cheapest stable approach.

The adaptive controller must be inexpensive enough that its own bookkeeping does not erase the optimization benefit. Prefer integer counters and already-maintained queue/pressure state over new high-frequency telemetry.

### J03 — HTTP/1.1 adaptive policy

**Status: complete.** QA-certified at head `1935288424174711525d98ad9cf5ca4be755146c`. The implementation is worker-scoped: existing connection pressure, queued bytes and active connection load feed the shared controller only when a new H1.1 connection is attached. LATENCY/BALANCED states keep NODELAY on; sustained THROUGHPUT state selects NODELAY off for the new connection only. Existing connections are never retuned, and explicit socket-context overrides remain authoritative.

Current production behavior remains the baseline:

- implicit bounded `end($body)` may coalesce response head + body;
- H1 defaults NODELAY on after protocol selection;
- explicit listener override remains authoritative;
- streaming/WebSocket paths remain unchanged.

The benchmark evidence shows that NODELAY is excellent for low/moderate-concurrency latency but can trade away aggregate throughput at heavy TLS saturation. That makes H1 interesting, but it is **not** a good candidate for per-request socket-option switching.

Rules for J03:

- never toggle NODELAY for individual responses;
- do not switch a live connection back and forth based on instantaneous request count;
- keep response coalescing semantic-driven, not load-driven, unless new evidence proves otherwise;
- first investigate whether worker pressure can influence the policy for **newly accepted H1 connections**;
- compare that against the simpler current always-NODELAY H1 behavior;
- if the adaptive connection policy does not clearly beat the current H1 default, close J03 as Keep.

Potential H1 load inputs:

- worker active connection count;
- connection pending-write bytes / pressure;
- worker aggregate queued bytes if cheaply available;
- recent accepted/request rate.

A configurable H1 crossover must not override an explicit user `tcp_nodelay` socket-context setting.

### J04 — HTTP/2 adaptive response scheduling

**Status: complete.** QA-certified after the adaptive selector was integrated without changing the 1 KiB hard ceiling. The live scheduler uses active streams, queued response bytes and transport pressure to select 1024 / 512 / 256-byte one-shot budgets; larger responses retain the normal scheduler.

HTTP/2 is the strongest immediate adaptive-response candidate because the current evidence already shows a crossover:

- tiny / low-multiplex responses strongly benefit from fewer immediate writes;
- the broad prototype regressed the 1 KiB / 100-stream case by about 15.6%;
- the production <1 KiB combined-wire-intent gate avoids that known regression but is static.

J04 should test whether the existing fast-path eligibility can improve by including connection state.

Candidate selection inputs:

- active request streams;
- stream outbound bytes;
- connection wire-queue bytes;
- transport pressure;
- flush-queue depth;
- available connection/stream flow-control credit.

Conceptual policy:

```text
tiny response
+ no pressure/backlog
+ low multiplex state
    -> one-shot HEADERS + DATA path

otherwise
    -> normal HTTP/2 scheduler
```

The existing <1 KiB wire-intent threshold remains the safety/performance baseline until evidence proves a better bounded threshold.

Important distinction:

- **hard H2 limits** such as `maxConcurrentStreams`, response-byte ceilings, frame limits, and flow-control limits never change;
- only scheduling-path selection may adapt.

J04 must also test whether an active-stream crossover adds measurable value beyond today's wire-intent + pressure checks. If not, keep the simpler current implementation.

### J05 — HTTP/3 adaptive pump scheduling

**Status: complete.** QA-certified at head `d4157718468e50ffa14b070a37757d63e297b028`. Outbound backlog independently scales the write budget; inbound ready/active stream pressure scales read and accept effort in opposite directions. Every selected value remains at or below `Http3Limits`.

This is the highest-value investigation.

Current H3 limits expose independent scheduling budgets such as:

- `maxWritesPerFlush = 128`;
- `maxConnectionsAcceptedPerPump = 64`;
- `maxStreamsAcceptedPerPump = 64`;
- `maxReadsPerPump = 256`;
- `maxInboundBytesPerPump = 262144`.

V4 showed that static variants can move throughput differently without producing one universal winner. That is compatible with the hypothesis that the best work budget depends on the current backlog shape.

Candidate H3 state inputs:

- active request stream count;
- streams ready to read/write;
- pending response bytes;
- per-stream transport pressure;
- QPACK encoder pending bytes;
- pending QPACK decoder instructions;
- accepted-connection/stream backlog;
- recent accept rate;
- ratio of inbound backlog to outbound backlog.

Candidate behavior:

```text
LOW / latency state
    -> smaller bounded per-turn work
    -> stronger fairness / faster return to poll

NORMAL
    -> current defaults

HIGH / outbound-dominant
    -> larger write budget if sustained write backlog exists

HIGH / inbound-dominant
    -> larger read budget if sustained readable backlog exists

ACCEPT-heavy
    -> acceptance budget remains bounded so new work cannot starve established streams
```

Do not increase every budget together merely because load is high. The controller should respond to the **direction of backlog**.

Any adaptive budget remains capped by the configured `Http3Limits`; adaptation chooses a value at or below the configured maximum unless later evidence justifies a separate adaptive ceiling contract.

### J06 — HTTP/3 poll policy

**Status: complete.** Active traffic retains readiness-driven polling, idle listeners may use a longer bounded wait, and pending handshakes clamp to 10 ms. Non-blocking calls remain non-blocking.

Treat QUIC poll timeout separately from pump-budget adaptation.

V4 already showed that the 50 ms default is not the active-traffic latency floor because readiness wakes the poll early. Therefore:

- do not reduce poll timeout just because active-stream count is high;
- investigate poll adaptation only for idle/busy CPU trade-offs, timer responsiveness, handshake progression, and shutdown/recycle responsiveness;
- compare current 50 ms against candidate idle/busy policies with CPU usage as a first-class metric.

Possible model:

```text
active ready/backlogged work
    -> poll returns from readiness; no artificial short timeout required

idle / no backlog
    -> allow normal blocking timeout

near timer/deadline work
    -> clamp timeout to the nearest required deadline
```

If the native poller/runtime already naturally gives this behavior from readiness + timer ownership, close J06 as Keep rather than adding a second timing controller.

### J07 — user-visible policy and crossover overrides

**Status: complete.** QA-certified at head `173626dbb5dab9b8b6dcf13f5dfe6531c680c94c`. `AdaptivePolicyMode` and `AdaptiveProtocolPolicy` are wired into HTTP/1.1, HTTP/2, and independent HTTP/3 inbound/outbound policy ownership. `AUTO`, `LATENCY`, `THROUGHPUT`, and deterministic `FIXED` modes are covered, with protocol-local crossover/EWMA overrides and hard limits kept separate.

Do not expose low-level adaptive knobs before their semantics are proven.

Preferred eventual policy shape, subject to evidence:

```text
AUTO        adaptive protocol-specific policy
LATENCY     fixed latency-biased strategy
THROUGHPUT  fixed throughput-biased strategy
FIXED       current/static configured limits and behavior
```

`AUTO` is the selected H2/H3 default; H1 remains FIXED. Full performance certification still requires J10.

Advanced users may eventually override protocol-specific crossover values, but there should be **no single global concurrency baseline** because HTTP/1.1 connections, HTTP/2 streams, and HTTP/3 pump pressure are different units.

Potential advanced configuration should therefore be protocol-scoped, for example conceptually:

```text
HTTP/1.1:
  low/high worker-pressure crossover

HTTP/2:
  low/high active-stream or pressure crossover

HTTP/3:
  low/high backlog crossover
  optional pump-budget ranges
```

The exact public API is intentionally undecided. First prove the adaptive model internally; only then decide whether existing options can own it or a small policy object is justified.

### J08 — transition, burst, and flapping validation

**Status: complete.** QA-certified at head `e392ee05009735fddf60fe0e7e4b98f60965bf05`. Tests cover sustained promotion/demotion, one-sample bursts, repeated crossover oscillation, and pinned profiles at the protocol strategy layer.

Static low/high benchmarks are insufficient.

Mandatory load-shape tests:

- steady low load;
- steady medium load;
- steady high load;
- ramp: low -> medium -> high;
- ramp down: high -> medium -> low;
- burst: low -> high -> low;
- repeated oscillation around the proposed crossover;
- mixed payload sizes;
- mixed short/streaming responses;
- TLS where applicable;
- long-lived H2/H3 connections whose active-stream count changes over time.

Record:

- state transitions;
- time spent in each state;
- transition frequency;
- RPS;
- p50/p95/p99;
- CPU;
- RSS;
- queue/backpressure peaks;
- fairness/starvation indicators;
- errors/timeouts/protocol failures.

A candidate that wins steady-state benchmarks but flaps or produces tail-latency spikes during transitions must be rejected.

### J09 — safety and invariants

**Status: complete.** QA-certified at head `e392ee05009735fddf60fe0e7e4b98f60965bf05`. Cross-mode tests assert H2/H3 adaptive work budgets never exceed configured maxima, hard protocol limits are unchanged by performance policy, and FIXED mode reproduces the static pre-adaptive behavior.

Adaptive policy is a **performance scheduler**, never a resource-limit controller.

It must not dynamically weaken or raise:

- max body/header/frame sizes;
- max concurrent stream ceilings;
- max lifetime streams;
- connection limits;
- queue byte ceilings;
- QPACK/HPACK safety limits;
- WebSocket limits;
- drain/lifecycle safety rules.

Protocol correctness, backpressure, fairness, graceful shutdown, and security boundaries remain deterministic regardless of adaptive state.

State transitions must not:

- lose queued bytes;
- double-consume flow-control credit;
- reorder required control traffic;
- starve streams/connections;
- alter already-observed terminal state;
- bypass explicit user overrides.

### J10 — benchmark matrix and promotion gate

**Status: awaiting external sustained certification.** The five-trial evidence at `17c373ac4ca93b73238a02d28c423450b9e31460` exercises one payload per protocol with 2–7-second phases; it is diagnostic evidence, not the full J10 matrix. H1 AUTO had conflicting results. H2/H3 passing those short comparisons does not establish sustained performance across the required workloads. The release decision explicitly retains H1 FIXED and H2/H3 AUTO, including direct QUIC entry points. This does not close the full J10 evidence gate. I8 remains separate.

The summary now records per-phase sample CV, successful RPM, minimum actual duration, concurrency, payload, build and environment identity. Strict comparisons require five valid matching trials, 30-second warm-up, at least 180 seconds per steady phase, and RPS CV below 2.5% in both profiles. Diagnostic mode preserves all existing regression budgets but reports inadequate duration/variance explicitly. A single-workload comparison always reports `promotion_certified: false`; promotion still requires all coverage and material-gain requirements below.

The PHP-native expanded runner and shared JSON case manifest define all 86 mandatory cases and all four profiles, with resumable exact-build trials and per-protocol evaluation. Python remains only for the pinned independent H2 and H3 transport clients. Material gain and transition overhead are quantified in `docs/benchmarks.md`. The runner is implemented; measured results must come from the prepared environment.

Each protocol is evaluated independently. J is not an all-or-nothing feature.

Compare at minimum:

```text
current static production behavior
fixed latency-biased candidate
fixed throughput-biased candidate
adaptive candidate
```

Across representative protocol matrices:

**HTTP/1.1**
- plaintext + TLS;
- tiny / 1 KiB / 16 KiB / 64 KiB;
- low / medium / saturated worker load;
- short keep-alive + longer-lived connections.

**HTTP/2**
- 2 B / 1 KiB / 16 KiB / 64 KiB;
- 1 / 8 / 32 / 100 active streams;
- tiny-only and mixed-payload connections;
- transport-pressure and flow-control constrained cases.

**HTTP/3**
- tiny / 1 KiB / 16 KiB / 64 KiB;
- low / medium / high active streams;
- inbound-heavy / outbound-heavy / balanced workloads;
- QPACK backlog cases;
- acceptance-heavy connection/stream churn;
- steady and transition workloads.

Promotion requirements:

- repeated trials with median + CV;
- p95/p99 must not materially regress for the target state;
- throughput must improve materially in at least one state without moving the loss to another common state;
- CPU/RSS cost must remain acceptable;
- state-transition overhead must be negligible;
- fairness and backpressure invariants pass;
- protocol/security/resource limits remain unchanged;
- user fixed-policy mode must reproduce deterministic non-adaptive behavior.

Decision outcomes per protocol:

- **HTTP/1.1 — Keep FIXED as the production default.** FIXED preserves NODELAY-on and bypasses admission sampling. AUTO remains available explicitly with bounded eight-connection sampling, but is not release-promoted because sustained throughput results were not repeatable within the 5% floor.
- **HTTP/2 — Keep AUTO as the production default by the release decision.** The bounded 1024 / 512 / 256-byte selector remains active; full sustained certification is pending.
- **HTTP/3 — Keep AUTO as the production default by the release decision.** Fair read rotation and resource ceilings apply in every mode. Full sustained certification is pending.
- **Fixed profiles retained.** LATENCY and THROUGHPUT pin their protocol profile; FIXED reproduces deterministic non-adaptive behavior.

### J sequencing

**Implementation and pending promotion batches:**

1. J01/J02 — shared normalized load model, EWMA, hysteresis and dwell;
2. J04 — adaptive HTTP/2 initial-response scheduling;
3. J05/J06 — adaptive HTTP/3 pump/backlog effort and poll timing;
4. J03 — worker-scoped HTTP/1.1 new-connection policy;
5. J07 — protocol-local public AUTO / LATENCY / THROUGHPUT / FIXED policy;
6. J08/J09 — transition/flapping validation and hard-limit invariants;
7. J10 — complete matrix runner and evaluator implemented; external sustained evidence remains pending.

Production code stayed on `feat/http-improvement`; playground-only patch/harness machinery was not migrated into the library branch.

---

## K — release follow-up hardening

**Status: runtime defects are resolved. H1 FIXED / H2-H3 AUTO defaults follow the release decision. J10 remains open for external evidence; I8 remains separate for final release certification.**

| ID | Finding | Required resolution | Status |
| --- | --- | --- | --- |
| K01 | HTTP/3 AUTO can starve later ready/request streams because each bounded read cycle restarts at the first peer stream | Persistent round-robin read cursor implemented for both scan/readiness paths with reserved-stream regressions; focused H3 + aioquic validation green | **Complete** |
| K02 | HTTP/1.1 AUTO admission samples every live connection, making admission sampling O(N) and aggregate growth quadratic | FIXED/LATENCY/THROUGHPUT bypass sampling; AUTO tracks at most 8 recent live connections in a worker-local bounded sampler; production default is FIXED | **Complete** |
| K03 | J10 performance promotion lacks sustained AUTO-vs-FIXED evidence | Complete matrix runner and validated CV/duration/provenance reporting distinguish diagnostics from sustained evidence; H1 FIXED / H2-H3 AUTO defaults follow the release decision | **Harness implemented; J10 evidence pending** |
| K04 | Accepted EWMA numerator/denominator values can overflow integer multiplication during observation | Public policy + controller reject denominators above the mathematically safe integer bound; boundary/max-int tests added | **Complete** |

### K01 — HTTP/3 bounded-fair read progress

The adaptive read budget may be smaller than the number of ready peer streams. Both `drainReadableStreams()` and `drainReadyStreams()` therefore require a persistent rotation cursor so the next pump resumes after the last stream that consumed/attempted budget rather than restarting at the first peer stream. Reserved unidirectional streams remain valid peers and must not starve request streams.

Acceptance requires regression tests for both pump paths with a reserved stream ahead of a request stream and a one-read budget.

### K02 — HTTP/1.1 admission sampling complexity

FIXED/LATENCY/THROUGHPUT modes must not sample the existing connection population at all. AUTO must not perform an unbounded full scan on every admission. Worker-scoped adaptive accounting samples a strict eight-connection recent subset while keeping exact active-connection count separate; this bounds admission work independently of total live population.

### K03 — reopen J10 evidence gate

Green correctness CI and policy microbenchmarks are necessary but insufficient for promotion. Five short single-payload trials did not close J10. The release decision retains H1 FIXED and H2/H3 AUTO at the options, direct QUIC connection/worker, and scheduler entry points. Regression tests explicitly cover the default contract and selectable policies.

Use the complete J10 matrix runner documented in `docs/benchmarks.md` in the prepared external environment. The original J10 matrix and performance requirements remain unchanged. No local result or individual workload comparison substitutes for that matrix.

### K04 — EWMA arithmetic safety

User-configurable EWMA parameters must never convert integer arithmetic to float and trigger a typed-property/argument failure during normal observation. The implementation must use overflow-safe integer math and/or validated safe bounds, with `PHP_INT_MAX`-scale regression tests.

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

V1-V7, D01-D18, J01-J09, and the runtime hardening fixes are implemented. J10 remains open for full adaptive performance certification; I8 remains open before final release certification. Neither is claimed passed by the diagnostic jobs.
