Performance evidence and threat boundaries
==========================================

This guide explains performance and security measurements without
asserting unverified improvements.
All numerical performance and release gates require matched
production-equivalent evidence, not one short local benchmark.

Performance controls
--------------------

Tune resource ceilings before pursuing peak throughput: event-loop
read/write work per turn, bounded worker job concurrency and queues,
connection admission, H1/H2/H3 stream budgets, HPACK/QPACK blocked
bytes, flow control, WebSocket payload buffers and QUIC poll timeouts.
Prefer the highest sustainable successful requests per minute (RPM)
under stable p99, RSS, CPU, FD, error and queue constraints.

The protocol-local adaptive policy defaults to FIXED. AUTO is
explicit opt-in and requires the reproducible promotion matrix, not
a noisy or partial benchmark. The native supervisor integrates the autoscale decision engine with real bounded worker scale-out, scale-in, recycle, and drain; the source-frozen full 1800-second mixed workload qualification passed on PHP 8.4 and 8.5. Hosted worker lifetimes still belong to their hosting servers.

Reproducible measurement topology
---------------------------------

The project supplies workload, environment and evidence runners:

.. code-block:: console

   php benchmarks/r0_host_profile.php
   php benchmarks/u01_executor_profile.php
   php benchmarks/u03_dial_profile.php
   php benchmarks/soak_resource_profile.php

For matched 2.1.2/3.0 comparisons use the controlled benchmark
topology and frozen baseline prepared by
benchmarks/matched_release_certification.sh, plus the
benchmarks/release_topology_inventory.py and
benchmarks/soak_runtime_telemetry.php scripts. Required final gates
are defined in the 3.0 release plan, including repeated warmed
successful throughput, sample variance, latency percentiles and
sustained process-tree resource soak.

The existing Benchmarks GitHub Actions workflow checks the diagnostic
profile harness. A passing diagnostic job is NOT a matched five-run
180-second release comparison or the 1800-second certification soak.
No performance certification is implied by this guide.

Threat boundaries
-----------------

Inbound threats include HTTP request smuggling, oversized headers,
HPACK/QPACK state amplification, slow or canceled peers, invalid
WebSocket masking/control frames and QUIC reset/GOAWAY churn.
Bounded parsers, per-session owners, frame/work admission quotas
and independent peer fault tests reduce exposure but do not replace
a full U14 fuzz and adversarial release audit.

Outbound threats include DNS rebinding and SSRF, origin or TLS
certificate confusion, credential leakage across redirects/proxies,
unsafe retries, socket cross-tenant reuse and unbounded worker queues.
The optional OutboundAddressPolicy rejects non-public candidates
before TCP connect and enforces equality with the kernel-reported
connected IP; applications without an explicit policy are not
automatically protected. TlsClientOptions verifies peer chain/name
and ALPN. PoolIsolationKey prevents treating different scopes,
trust revisions or proxy identities as one reusable identity.
Bounded connection pooling, CONNECT/SOCKS5 tunnels, and H1 redirect/retry policies exist as separate opt-in primitives. A general automatic cross-protocol H1/H2/H3 HTTP connection pool is **not** implemented; do not interpret the primitives as a unified high-level session manager.

Filesystem threats include symlink traversal, TOCTOU, directory
permissions, cross-device rename, partial writes and false fsync
durability claims. The process-backed file worker does not currently
promise a jailed sandbox or crash-atomic replacement. Restrict paths
at the application boundary and use a separately audited storage
transaction when durable replacement is required.

QA provenance
-------------

Tests and measured results should identify the source revision and
runtime environment. Syntax checks do not replace real protocol tests. Use the appropriate real hosted, native, Swoole,
portable, independent HTTP/3 and PHPForge matrices and preserve
all failure logs and resource measurements.

Traceable benchmark and security gates
--------------------------------------

The following numbers are **required measurement criteria**, not claims
that every deployment has achieved them.

.. list-table:: Reproducible gates and source-of-truth instrumentation
   :header-rows: 1
   :widths: 24 24 52

   * - Metric or control
     - Expected gate
     - Executable source
   * - Matched throughput
     - Five paired 180-second trials
     - ``benchmarks/matched_release_certification.sh`` and
       ``benchmarks/release_topology_inventory.py``
   * - Sample stability
     - Successful-RPS CV below 2.5 percent for both builds
     - ``benchmarks/release_topology_inventory.py`` and the
       sustained-performance comparison
   * - Resource/GC/OPcache soak
     - 1800-second nominal duration; validated minimum 1740 seconds
     - ``benchmarks/soak_runtime_telemetry.php``
   * - Per-operation file bytes
     - At most 1048576 bytes per executor operation
     - ``src/Filesystem/AsyncFileSystem.php``
   * - Outbound pool isolation
     - Tenant/host/TLS/ALPN/proxy policy separation
     - ``src/Network/PoolIsolationKey.php`` and
       ``tests/PoolIsolationKeyTest.php``

Run the retained benchmark scripts and independent TLS, QUIC, host and
security tests. A green Sphinx build or profile does not substitute for
matched protocol comparisons or a sustained mixed-load resource soak.

HTTP/1.1 diagnostic send-buffer contention
------------------------------------------

The adaptive HTTP/1.1 benchmark now distinguishes a transient
zero-byte nonblocking ``fwrite()`` from an actual failed write. A zero-byte
write retains the current bounded request, preserves the unchanged
two-second timeout and retries through select readiness; ``false``
remains an error. Transport counters separate connection, read and write
failures and count blocked writes. This is an accounting/correctness fix,
**not permission to ignore server failures, change concurrency or reduce
release throughput and p99 acceptance budgets**. The strict benchmark still
rejects all failed/incomplete requests. An earlier PHP 8.5 H1 adaptive
matrix failed with 256 errors; its source cause is not considered
certified until matched workload QA passes.
