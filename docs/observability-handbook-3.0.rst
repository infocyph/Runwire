Observability, diagnostics and incident triage
==============================================

Observable signals are part of correctness
------------------------------------------

A service with a listening port can still be failing: requests may
timeout, the event loop may starve, accepted H2 streams may be reset,
or child workers may restart endlessly. Measure successful response
completion and the owner lifecycle, not only the number of sockets
created.

Use structured logs, versioned metrics and reproducible source
evidence, with secrets redacted. A human-readable status line is not
a replacement for an event, timestamp and error category.

Metrics taxonomy
----------------

.. list-table:: Minimum operational metric contract
   :header-rows: 1
   :widths: 29 35 36

   * - Signal
     - What to capture
     - Why it matters
   * - Throughput
     - Successful RPS and attempted/admitted/completed counts
     - Detect silent loss or retries hiding errors
   * - Latency
     - p50/p95/p99 request latency and reactor callback delay
     - Distinguish slow downstream from busy loop
   * - Connections
     - Active sockets, pending dials, leased/idle pools
     - Catch capacity and reuse leaks
   * - Protocol
     - H1 framing failures, H2 stream resets/GOAWAY, H3 QUIC errors
     - Isolate protocol-specific regression
   * - Workers
     - Desired/current count, generation, restarts, readiness, pending stops
     - Detect failed autoscale or reload
   * - Memory and OS
     - Per-worker/parent RSS, open FD, CPU user/system, process count
     - Detect resource growth
   * - Executor
     - Process count, queued and active jobs, errors, cancellations
     - Find off-loop file/DNS bottlenecks
   * - Security
     - TLS peer verification, auth rejection and policy violations
     - Distinguish attack from dependency outages

Counters must have stable semantics across PHP versions and the host
driver. An accepted request means the protocol handler has reached
admission; a connection attempt is earlier in the lifecycle.
Never convert a failed request into an apparent successful RPS by
counting submission rather than completion.

Diagnostics surfaces
--------------------

The source provides `LoopDiagnosticsSnapshot`,
`CoroutineDiagnosticsSnapshot`, `RuntimeMetricsSnapshot`,
`SupervisorStatus` and worker lifecycle events. Their precise
fields are part of the generated :doc:`api-reference-3.0`; do not
assume all metrics exist on every driver or that host-owned
runtimes expose native worker PID details.

The native supervisor's `status()` and `onEvent()` can be used by
an operational control plane. Emit enough identity to correlate
worker group, PID, slot and generation, but avoid unbounded
high-cardinality labels such as arbitrary user IDs or full URLs.

Event-loop latency versus response latency
------------------------------------------

Event-loop timer delay measures scheduling contention. HTTP response
latency includes DNS/dial/TLS, queue wait, application processing,
socket backpressure, peer read rate and connection closure.
An excellent loop p99 does not prove users experienced low p99.

For example, the C3 native 120-second harness recorded low loop jitter
and successful HTTP local-client p99 separately. Those measurements
are staging diagnostics, not general production performance promises.

Structured logging
------------------

Include timestamp, source revision, runtime driver, worker group,
generation, operation, request correlation and terminal reason.
For transport errors, include bounded protocol state and high-level
error code. Avoid logging raw Authorization, Cookie, Proxy-Authorization,
TLS private keys, client-cert contents and response bodies.

Do not print stack traces for every transient EAGAIN/EWOULDBLOCK from a
nonblocking accept poll; record expected retry conditions separately.
Do **not** globally suppress PHP warnings: unexpected failures should
remain visible and fail QA.

Fault taxonomy
--------------

.. list-table:: Diagnose by symptom
   :header-rows: 1
   :widths: 31 69

   * - Symptom
     - Check
   * - Reactor lag rises
     - Blocking callbacks, long task loops, GC pressure, synchronous DNS/file ops
   * - Pending dial count grows
     - Resolver worker pressure, unreachable IPs, incomplete cancellation
   * - TLS works on IP but not hostname
     - Original host/SNI/ALPN and trust root verification
   * - H2 sibling resets on stop
     - GOAWAY, accepted header state, drain timer and stream output credit
   * - H3 stream loss/reorder
     - QUIC capability, QPACK state, flow control and packet diagnostics
   * - Stable RPS but growing RSS
     - Unbounded body queues, idle pools, leaked request scopes
   * - Worker crash loop
     - Restart budget, exit code, callback exception and retained sockets
   * - Autoscale oscillates
     - Invalid telemetry, window length, min/max and cooldown configuration
   * - File jobs stall
     - Process spawn availability, queue length and worker lifetime
   * - cPanel/host fails to bind
     - Port permissions, process/extension restrictions and chosen driver

Triaging a failing request
--------------------------

1. Identify protocol, transport and host driver.
2. Find the request or stream admission event and terminal completion.
3. Identify the exact failure phase: DNS, connect, TLS, parser,
   application, writer, drain or forced termination.
4. Correlate the owning PID/generation and cancellation deadline.
5. Check metrics for queue growth or loop starvation.
6. Reproduce with one independent peer before introducing load.
7. Run targeted tests, then the full CI lane on the same source SHA.
8. Capture a sanitized evidence artifact and the rollback decision.

Incident probes and golden paths
--------------------------------

.. code-block:: console

   vendor/bin/pest tests/Http2ConnectionTest.php tests/DialerTest.php
   php .ci/c3_listener_fork_smoke.php
   php .ci/c3_autoscale_http_traffic.php

For H3 packet loss/reorder and matched H2 reference, prefer the
dedicated automated workflows. File a bug with minimal steps, actual
and expected wire behavior, PHP/runtime version, ext-quic capability
and exact source SHA. See :doc:`performance-qa-handbook-3.0` and
:doc:`production-operations-3.0`.


Explicit W3C trace context propagation
--------------------------------------

Runwire 3.0 provides an opt-in, dependency-free
`Tracing\\TraceContext` carrier that strictly validates version-00
`traceparent` and bounded `tracestate`, creates child span IDs and
injects or extracts sanitized HTTP headers. Bind the carrier to an
explicit `RequestContext`; completion clears the owned attributes.
Fiber/executor code must carry that request context explicitly.
No ambient global trace context, automatic distributed context forwarding
or OpenTelemetry SDK is claimed. An application may bridge the carrier to
an OpenTelemetry SDK on its own integration boundary.

Do not automatically reuse inbound trace headers across tenants. Parsing
rejects duplicate traceparent, all-zero identities and malformed or
oversized tracestate. The outbound `inject()` method replaces earlier
trace headers, retaining other headers. Validation is covered by
`tests/TraceContextTest.php`.


Scoped Fiber trace propagation
------------------------------

`Tracing\\TraceScope` adds explicit per-owner Fiber propagation without
process-wide static context. Use `run($trace, $callback)` for request-local
ownership and `wrap($callback)` before dispatching a task to another Fiber
or deferred callback. Restoration is guaranteed in `finally`, including
exceptions. A suspended Fiber retains its own context while the parent
continues independently; `WeakMap` does not retain completed Fibers.
Create separate scope objects after fork and for untrusted tenant boundaries.
This is not an automatic PHP executor IPC or SDK integration: pass validated
carrier headers or metadata explicitly at each process boundary.
