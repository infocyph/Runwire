# Runwire 2.2 — R3 HTTP/1/WebSocket and R4 H2/H3 batch QA

## R3 functional batch: 8/9 complete, R3.09 promotion gate open

**R3.01** Performance evidence: `benchmarks/http1_sustained_bench.php`, existing benchmark PHP 8.4/8.5 provenance, HTTP1 validation PHPBench subject, real keepalive and concurrency sweep from R0. These establish relative cost categories but not a candidate RPM win.

**R3.02** Implemented [ByteQueue batched read](https://github.com/infocyph/Runwire/commit/0970075c666e353585034723f02934a5fc86d94e) — release reserved bytes once after each read and compact after the batch, rather than invoking discard+compact per consumed chunk. Added `ByteQueueReadTest` (partial reads, 300-chunk compaction, shared quota and interleaved discard/reuse). [PHPForge QA on this candidate](https://github.com/infocyph/Runwire/actions/runs/39373535309): both PHP 8.4/8.5, lowest/stable Pest and analysis passed; HTTP/3 PHP 8.5 focused tests/aioquic interoperability passed, but longer diagnostic/soak initially in progress, **not counted as final acceptance**. [R3 Swoole acceptance](https://github.com/infocyph/Runwire/actions/runs/39373535102) passed all four modes. [Portable](https://github.com/infocyph/Runwire/actions/runs/39373534881) passed.

**R3.03:** `Http1ConnectionTest` covers pipelined ordering, keepalive limits, fragmented chunk bodies, 100 Continue, HEAD/no-body and invalid smuggling. **R3.04:** TLS live-client/ALPN behavior verified by `ResponseTransferTlsTest`, native TLS suites and semantic parity; no protocol changes. **R3.05:** `ResponseTransferTest` and `ResponseTransferTlsTest` verify bounded chunk pressure, cooperative cancellation, source ownership and slow-client TLS. **R3.06:** `WebSocketIntegrationTest` verifies origin policy, masking, ping/fragmentation, close and bounded framed buffers. **R3.07:** `Http1AdaptiveConnectionSamplerTest` covers AUTO vs FIXED sampling; current adaptive matrix does not justify default change, so preserved FIXED. **R3.08:** `Http1AbuseAcceptanceTest`, `Http1BackpressureTest`, `Http1WorkerBudgetTest` cover slowloris/incomplete headers, oversized/conflicting fields and body caps, EOF, shared budgets and pressure.

**R3.09 remains OPEN:** distinct H1 plaintext/TLS, low/high concurrency, small/1MiB payloads, per-send syscalls plus stable full-server RPM/p99/RSS head-to-head on the final SHA have not been produced. No certified throughput-gain headline.

## R4 audit: 5/10 complete, focused interoperability pending

**R4.03:** `Http2ConnectionTest`, `Http2AbuseTest` verify fragmented/accepted header blocks across drain, per-stream cancellation, control-frame caps, GOAWAY, bounded response buffers and independent sibling flow-control. **R4.05:** `Http3SessionTest`, `Http3AbuseFaultAcceptanceTest` validate QPACK-blocked streams, cancellation, max buffered bytes, lost critical stream, churn and idle drain. **R4.07:** `AdaptivePromotionMatrixTest`, `AdaptiveProtocolTransitionTest` and existing adaptive H1/H2/H3 CI enforce FIXED baseline and refuse unstable/noisy AUTO promotion. **R4.08:** `HpackTest`, `QpackTest`, abuse suites include malformed header representation, Huffman/dynamic table bounds, index/Base overflow and decompression ceilings. **R4.10:** `HttpProtocolSemanticParityTest` checks the unified HttpRequest/ResponseWriter application-visible contract across H1/H2/H3.

**R4.01/.02/.04/.06/.09 remain OPEN:** independent full-saturation H2/H3 throughput and QPACK/HPACK hotspot A/B, exact ngtcp2 client validation on final candidate, and concurrent cancellation-storm soak require release-grade data. The focused QUIC suite on current source is running; no skipped or failed host mode is counted as passing.

## Release qualification and known constraints

Real request correctness and resource limits must remain mandatory. R3/R4 review-only completion does not mean final R8 release qualification passed. `2.1.2` immutable baseline `7e9bf20ad0737a187dfed77b723791b141b36378`. Infbyte consumer still blocks on unpublished Foundation `^3.0.1`; no cross-repo edits or Composer alias substitution.
