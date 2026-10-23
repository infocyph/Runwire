# Runwire 2.2 — R3 HTTP/1/WebSocket and R4 H2/H3 batch QA

**Canonical ledger (2026-10-23, audited from 95 task IDs):** **74/95 checked**; seven unchecked R5.02–R5.08 explicitly deferred beyond 2.2; **74/88 active-scope done (84.1%), 14 in-scope remaining**. Completed batch totals: R0 9/11; R1 7/8; R2 9/9; R3 8/9; R4 6/10; R5 2/9 (7 deferred); R6 9/10; R7 10/10; R8 8/10; R9 6/9. **NOT READY FOR RELEASE:** H2/H3 sustained qualification, native multi-protocol rolling recycle, frozen-candidate certification, final QA/risk approval remain open.

## R3 functional batch: 8/9 complete, R3.09 promotion gate open

**R3.01** Performance evidence: `benchmarks/http1_sustained_bench.php`, existing benchmark PHP 8.4/8.5 provenance, HTTP1 validation PHPBench subject, real keepalive and concurrency sweep from R0. These establish relative cost categories but not a candidate RPM win.

**R3.02** Implemented [ByteQueue batched read](https://github.com/infocyph/Runwire/commit/0970075c666e353585034723f02934a5fc86d94e) — release reserved bytes once after each read and compact after the batch, rather than invoking discard+compact per consumed chunk. Added `ByteQueueReadTest` (partial reads, 300-chunk compaction, shared quota and interleaved discard/reuse). [PHPForge QA on this candidate](https://github.com/infocyph/Runwire/actions/runs/39373535309): both PHP 8.4/8.5, lowest/stable Pest and analysis passed; HTTP/3 PHP 8.5 focused tests/aioquic interoperability passed, but longer diagnostic/soak initially in progress, **not counted as final acceptance**. [R3 Swoole acceptance](https://github.com/infocyph/Runwire/actions/runs/39373535102) passed all four modes. [Portable](https://github.com/infocyph/Runwire/actions/runs/39373534881) passed.

**R3.03:** `Http1ConnectionTest` covers pipelined ordering, keepalive limits, fragmented chunk bodies, 100 Continue, HEAD/no-body and invalid smuggling. **R3.04:** TLS live-client/ALPN behavior verified by `ResponseTransferTlsTest`, native TLS suites and semantic parity; no protocol changes. **R3.05:** `ResponseTransferTest` and `ResponseTransferTlsTest` verify bounded chunk pressure, cooperative cancellation, source ownership and slow-client TLS. **R3.06:** `WebSocketIntegrationTest` verifies origin policy, masking, ping/fragmentation, close and bounded framed buffers. **R3.07:** `Http1AdaptiveConnectionSamplerTest` covers AUTO vs FIXED sampling; current adaptive matrix does not justify default change, so preserved FIXED. **R3.08:** `Http1AbuseAcceptanceTest`, `Http1BackpressureTest`, `Http1WorkerBudgetTest` cover slowloris/incomplete headers, oversized/conflicting fields and body caps, EOF, shared budgets and pressure.

**R3.09 remains OPEN:** distinct H1 plaintext/TLS, low/high concurrency, small/1MiB payloads, per-send syscalls plus stable full-server RPM/p99/RSS head-to-head on the final SHA have not been produced. No certified throughput-gain headline.

## R4 audit: 6/10 complete; independent QUIC interoperability passed, deeper profiling/load acceptance remains open

**R4.03:** `Http2ConnectionTest`, `Http2AbuseTest` verify fragmented/accepted header blocks across drain, per-stream cancellation, control-frame caps, GOAWAY, bounded response buffers and independent sibling flow-control. **R4.05:** `Http3SessionTest`, `Http3AbuseFaultAcceptanceTest` validate QPACK-blocked streams, cancellation, max buffered bytes, lost critical stream, churn and idle drain. **R4.07:** `AdaptivePromotionMatrixTest`, `AdaptiveProtocolTransitionTest` and existing adaptive H1/H2/H3 CI enforce FIXED baseline and refuse unstable/noisy AUTO promotion. **R4.08:** `HpackTest`, `QpackTest`, abuse suites include malformed header representation, Huffman/dynamic table bounds, index/Base overflow and decompression ceilings. **R4.10:** `HttpProtocolSemanticParityTest` checks the unified HttpRequest/ResponseWriter application-visible contract across H1/H2/H3.

**R4.01/.02/.04/.06/.09 remain OPEN:** independent full-saturation H2/H3 throughput and QPACK/HPACK hotspot A/B, exact ngtcp2 client validation on final candidate, and concurrent cancellation-storm soak require release-grade data. The focused QUIC suite on current source is running; no skipped or failed host mode is counted as passing.

## Release qualification and known constraints

Real request correctness and resource limits must remain mandatory. R3/R4 review-only completion does not mean final R8 release qualification passed. `2.1.2` immutable baseline `7e9bf20ad0737a187dfed77b723791b141b36378`. Infbyte consumer still blocks on unpublished Foundation `^3.0.1`; no cross-repo edits or Composer alias substitution.

## Verified independent HTTP/3 interop qualification — R4.06 DONE (2026-10-20)

At source SHA `f804a5a6f6e7d9844dd18faedc15cba001bbc80f`, [Security & Standards run 39374716440](https://github.com/infocyph/Runwire/actions/runs/39374716440) recorded:
- PHP 8.4, container-built native `php-quic`, OpenSSL >=3.5: focused HTTP/3 protocol and **aioquic 1.3.0** independent client interoperability and 128-request native HTTP/3 soak passed.
- PHP 8.5: the same HTTP/3 native and aioquic gates passed, plus **ngtcp2 v1.25.0** `osslclient` built with **nghttp3 v1.18.0** passed an external HTTP/3 client request, validated the response body `runwire-ngtcp2-ok`, and executed the adaptive/soak gates.
- PHP 8.4 ngtcp2 checkout/build/run was intentionally **skipped** by workflow matrix; no claim that independent ngtcp2 was tested there.
- The extension source currently tracks `mikepultz/php-quic@main`, which is a remaining reproducibility risk for final R8.04/R8.10 ABI manifest; R4.06 tests itself were actually executed and passed.
- Exact independent client job IDs: PHP 8.4 `118470344941`; PHP 8.5 `118470345205`. R4.06 is marked DONE on the canonical plan; other R4 stress/throughput tasks remain open.

## Protocol core cost measurements — supporting R4.02/R4.04 evidence only (2026-10-20)

[Benchmarks 39427240739](https://github.com/infocyph/Runwire/actions/runs/39427240739) attached PHP8.4 and PHP8.5 PHPBench artifacts, each 5 measured iterations per existing ProtocolCoreBench subject, 1,000 repetitions, one warmup. The fields below are exact reported PHPBench mode values, NOT server latency or successful RPM:

| Subject | PHP 8.4 mode | PHP 8.5 mode |
|---|---:|---:|
| H2 HPACK encode | 20.681 | 14.346 |
| H2 HPACK decode | 35.487 | 23.093 |
| H3 QPACK dynamic round-trip | 211.869 | 153.302 |
| H3 QPACK static encode | 87.997 | 60.213 |
| H3 QPACK static decode | 97.505 | 65.860 |
| H3 frame encode | 0.191 | 0.123 |
| H3 frame decode | 1.015 | 0.678 |

These represent **different algorithms and workload shapes**, not an apples-to-apples HPACK-vs-QPACK speed comparison; distinct runner versions/conditions are also not evidence of a production-version gain. No rewrite or performance improvement claim is authorized by this profile alone. **R4.02 and R4.04 remain OPEN** pending integer/Huffman, frame/scheduling, QUIC poll/write and representative end-to-end attribution. The release primary metric remains sustained successful RPM.

## Profiling work and certification boundary — 2026-10-20

[Protocol profiler code `afa381f`](https://github.com/infocyph/Runwire/commit/afa381fa82dcc266043e4a6b1e2747ee89e88786) and [Benchmarks run 39433339538](https://github.com/infocyph/Runwire/actions/runs/39433339538) add direct HPACK Huffman/prefixed integer, H2 complete/split frame parse, QPACK prefixed integer and H3 QUIC varint cost subjects alongside existing codecs. PHPBench 1,000-rev/5-iteration mode values compare local isolated operations only. HTTP2 adaptive real client diagnostics are NOT statistically qualified and cannot certify end-to-end throughput, header-heavy flow-control or poll/write buffer gains. R4.01/.02/.04/.09 stay unchecked until the missing workload, polling/scheduling, cancellation-storm and stable evidence exists. Current tracker is 6/10 for R4; R8.04 real transport integration is separately complete.


### R4 protocol profiling expansion and QA — 2026-10-20

**Source:** [benchmark subjects `561edbf`](https://github.com/infocyph/Runwire/commit/561edbfebd0d0ec688580cff867450a73c891414), [PHPForge order-only fix `db9bfe7`](https://github.com/infocyph/Runwire/commit/db9bfe767f8b893348d2fcd7b1a7c16267db0e4d). No production runtime classes were changed. Three reproducible additional PHPBench subjects measure **HTTP/2 request header validation**, **HTTP/3 request header validation**, and **QPACK dynamic insert → blocked decode → encoder instructions → unblock → acknowledgment**. Pest tests check valid request-target output across H2/H3, and that a QPACK block actually waits for unblocking and returns the expected five fields; existing H2/H3 malformed-header and bounded-QPACK abuse tests remain in place.

[Protocol + host PHP8.4/8.5 core benchmark jobs 39464102766](https://github.com/infocyph/Runwire/actions/runs/39464102766) **both passed** on source `561edbf`, with 18 measured `ProtocolCoreBench` subjects in each PHP version. Immutable artifacts: [PHP 8.4 #12283493850](https://github.com/infocyph/Runwire/actions/runs/39464102766/artifacts/12283493850), [PHP 8.5 #12284460759](https://github.com/infocyph/Runwire/actions/runs/39464102766/artifacts/12284460759). Selected PHPBench `mode` readings, in raw report units (isolated operations with unlike workloads, **not** comparable across runner hosts or a request RPM claim):

| Subject | PHP 8.4 `mode` | PHP 8.5 `mode` |
|---|---:|---:|
| H2 request header validation | 6.2365 | 3.1714 |
| H3 request header validation | 6.2890 | 3.1394 |
| QPACK dynamic table roundtrip | 291.6636 | 143.7608 |
| QPACK forced blocked→unblocked roundtrip | 199.3141 | 97.8706 |
| HPACK decode | 45.5124 | 22.2764 |
| H3 QUIC varint roundtrip | 0.6495 | 0.2930 |

The two QPACK full-state subjects have much larger *per-operation work* than integer-only and header-validation subjects. This **prioritizes additional isolation of dynamic QPACK and scheduler integration**, not a blanket conclusion that QPACK is the end-to-end bottleneck: the subjects perform different work, are not production-frequency weighted and the CI hosts differ. Do not divide one subject's `mode` by another to claim runtime speedup.

**QA:** [four PHPForge stable/lowest PHP8.4/8.5 Pest jobs 39464104137](https://github.com/infocyph/Runwire/actions/runs/39464104137) passed tests and static analysis, but **all failed Pint `ordered_class_elements`** because new benchmark methods were inserted out of alphabetical order. The order-only [fix `db9bfe7`](https://github.com/infocyph/Runwire/commit/db9bfe767f8b893348d2fcd7b1a7c16267db0e4d) preserves benchmark semantics. [New QA run 39464797304](https://github.com/infocyph/Runwire/actions/runs/39464797304) on the fixed source was still pending at the latest evidence read; do not call it green prematurely.

**Canonical tracker stays at 72/95 checked, seven R5 tasks deferred, 72/88 in 2.2 scope complete (81.8%), 16 open.** R4.02 remains OPEN for H2 response scheduling/output allocation hot-path attribution and production-equivalent workload/malformed-input validation; R4.04 remains OPEN for **actual QUIC poll/write budgets and blocked-stream pressure under live H3 clients**, not just local QPACK codec cost. R4.01/R4.09 and R8 matched release certification remain open. Only Runwire was modified; Foundation, Infbyte, Rust library siblings, merge, tag and publication are untouched.


### 2026-10-22 — Runwire-only continuation: live H2/host evidence; H3/R6 gates remain open

**Tested Runwire PR #9 source evolution:** `86ad8578` independent H2 TLS fault tests, `6eb9e52a` native QUIC cancel-storm harness, `13dc3500` and `38a01fcc` native peer STOP_SENDING cancellation cleanup and its focused regression/QA fix, `b40843a3` onward real FrankenPHP container acceptance, `688df0f5` onward native supervisor rolling reload fixture. Current code head immediately before this documentation commit: `34d59116f0f89a5e0b8980ae39a193b884573705`. All changes remain entirely within Runwire.

- **H2 diagnostic acceptance: PASS.** [Benchmarks #39742667463](https://github.com/infocyph/Runwire/actions/runs/39742667463) included actual TLS/ALPN `h2`, one deliberately stalled 65,536-byte response with a 8,192-byte receive window, 12 sibling 1 KiB requests completing correctly and 13 request stream cancellations. The test is diagnostic/fault verification, not a 5×180-second throughput comparison or full R4.09 closeout.
- **FrankenPHP live integration: PASS PHP 8.4 and 8.5.** [Real Hosted Runtime Acceptance #39748162122](https://github.com/infocyph/Runwire/actions/runs/39748162122) validated standalone Runwire under a real `dunglas/frankenphp` worker on both PHP versions, correct GET targets, process PID 1 reuse, and successive worker-local request counts (2,3,4). Initial hosted QA false negatives were a missing CI `ext-event` and an invalid assertion requiring PID >1; both fixed without weakening request identity checks. **R6.10 remains open** pending actual RoadRunner and the complete supported-host matrix; the current real-host workflow covers FrankenPHP worker only.
- **Native worker life-cycle expansion: not yet QA accepted.** Runwire's prefork fixture now checks distinct generations and live H1/TCP/UDP responses through crash/automatic restart/manual recycle and a subsequent `Supervisor::reload()`. The earlier new test [Security & Standards #39747439727](https://github.com/infocyph/Runwire/actions/runs/39747439727) showed four successful live response generations, but failed an unsupported `WORKER_RECYCLE_COMPLETED` event sequencing assumption; that redundant expectation was removed at `6c88a514`, preserving actual network correctness and `RELOAD_STARTED`/`RELOAD_COMPLETED` checks. Current exact-head standard QA is required; **R6.02 still open** until real H2/H3 rolling-recycle traffic and completion evidence are present.
- **H3 real fault harness: unresolved and not waived.** Independent aioquic exercises 18 QUIC stream cancellations across three rounds, requires 30 complete healthy siblings and one successful same-connection fresh request. Early 64 KiB versions intermittently failed with sibling `TimeoutError()` on a 5-second client read limit. A subsequent 16 KiB variant initially failed `response exceeded expected body size` because the server still returned 64 KiB; **`34d59116` fixes server/client response-size parity**, preserving strict body verification. PHP 8.4+8.5 exact-head fault acceptance and full H3 stress/saturation/transport attribution are **not yet confirmed passing**. Keep R4.01/.02/.04/.09 open. Never substitute a short fault test for sustained successful RPM.
- **Release state:** Canonical **73/95 checked**, 7 R5 dialer tasks deferred, **73/88 in scope DONE (83.0%), 15 active open**. The 2026-10-20 five-job H1 matched release certification #39462685239 remains valid historical source evidence on `145b52b6`, **not** a new exact-final-head certification following the native QUIC source modification. Still no complete H2/H3 saturation/performance, all supported host modes, long live reload across transports, frozen exact-source release gate, risk signoff or maintainer publication permission. No merges/tags/releases; no Foundation/Infbyte/Rust sibling activity.

### Verified independent QUIC reset-fault recovery — PHP 8.4 and 8.5

At [Security & Standards #39828761049](https://github.com/infocyph/Runwire/actions/runs/39828761049), real aioquic H3 cancellation with the native php-quic backend succeeded for **both PHP versions**. 18 STOP_SENDING + RESET_STREAM cancellations, 31 successful sibling body/status checks and same-connection fresh request passed. The corresponding Runwire native `PhpQuicTransport` write/finish implementation isolates observed per-stream STOP_SENDING while preserving non-reset transport exceptions, with regression tests. This is a verified R4.09 **partial** fault slice, not sustained multi-protocol rolling recycle, QPACK/HPACK bottleneck attribution, or R4.01/R4.09 closure.


### 2026-10-23 — active R4 protocol and R6 mixed native lifecycle engineering

**Runwire-only changes:** [`4c9f49d`](https://github.com/infocyph/Runwire/commit/4c9f49d7927276f4cfc9d68306f7f0a027e0ce10) added independent real H2 HPACK dynamic-table, 8–16 KiB flow-control and slow-peer measurements plus aioquic H3 QPACK delayed insertions/blocked dependencies, uploads and connection churn, with real header-wire byte, throughput, p99, server/client CPU, RSS and correctness artifacts; [`9744969`](https://github.com/infocyph/Runwire/commit/97449691fe392c4d466c2a816fc78fe69e1cc3eb) installed missing CI-only `jq` in QUIC container. [`a23de05`](https://github.com/infocyph/Runwire/commit/a23de052d206793225eb40d2f26259f4a1911b20) introduced actual native supervisor TLS H1/H2, QUIC H3, framed TCP and UDP acceptance across SIGKILL restart, SIGHUP reload and manual coordinated H1/H3 recycle. [`ca33d0e`](https://github.com/infocyph/Runwire/commit/ca33d0eb9557d2c8298c342119541474347b4b4e) ensured an actual H2 client request overlaps reload and samples the real server PID.

**Negative test finding (not a release correctness verdict):** independent [Security & Standards #39887199272](https://github.com/infocyph/Runwire/actions/runs/39887199272) caught a `BrokenPipeError` when the original *continuous new-stream* H2 benchmark sent another request on a retiring TLS worker. [`581e1d5`](https://github.com/infocyph/Runwire/commit/581e1d555b5b9f2e5ee83883451fec93cc5876f8) replaced the reload test's H2 component with a fixed two-stream real independent client: validate 200 responses, exact complete bodies, and no retry of a partially accepted stream; only pre-response/GOAWAY reconnects are allowed. **Do not waive real loss of accepted responses**. The new exact-head CI on `581e1d555b5b9f2e5ee83883451fec93cc5876f8` must pass before R6.02 can be accepted, and R4.01/.02/.04/.09 require positive recorded protocol-pressure artifacts and correct production-equivalent criteria rather than a successful short diagnostic alone.

**Certification initiated without merging:** [Release Certification #39887279759](https://github.com/infocyph/Runwire/actions/runs/39887279759) was explicitly triggered on immutable `ca33d0eb9557d2c8298c342119541474347b4b4e` for its native H1 matched 5×180s / 30-minute soak / Runwire-only PHP8.4/8.5 clean package and PHPForge guard. Because the mixed-lifecycle client has since changed, that workflow **cannot certify this final head `581e1d555b5b9f2e5ee83883451fec93cc5876f8`**. Do not close R8.05 or claim 2.2 ready on the old run. After source/test/docs freeze, repeat exact-SHA certification and verify all complete artifacts.

**Canonical evidence-led accounting is unchanged at 74/95 checked, seven R5 items deferred, 74/88 in-scope (84.1%), 14 open.** R4.01/.02/.04/.09, R6.02, sustained R0/R1/R3, R8.05/.10 and R9.06/.07/.09 remain unaccepted. No Foundation, Infbyte, sibling Rust repos, merge, release tag or publication.
