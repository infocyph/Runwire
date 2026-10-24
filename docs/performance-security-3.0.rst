Runwire 3.0 performance evidence and threat boundaries
======================================================

This development document reports no unverified percentage or
throughput improvement. Runwire 2.1.2 remains the published release.
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
a noisy or partial benchmark. The native autoscale policy and pure
decision engine do not currently create, recycle or drain workers.

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
Complete connected pools, proxy negotiation, redirects and credential
policies remain separate unimplemented feature gates.

Filesystem threats include symlink traversal, TOCTOU, directory
permissions, cross-device rename, partial writes and false fsync
durability claims. The process-backed file worker does not currently
promise a jailed sandbox or crash-atomic replacement. Restrict paths
at the application boundary and use a separately audited storage
transaction when durable replacement is required.

QA provenance
-------------

Source and tests are tied to the immutable commit and workflow run
IDs recorded in the canonical 3.0 tracker. No review should promote
candidate status based only on documentation or a green standalone
syntax check. Use the appropriate real hosted, native, Swoole,
portable, independent HTTP/3 and PHPForge matrices and preserve
all failure logs and resource measurements.

Traceable benchmark and security gates
--------------------------------------

The following numbers are **required measurement criteria**, not claims
that unreleased Runwire 3.0 has achieved them.

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
       3.0 development-plan U15.09 proof gate
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

The Benchmarks workflow runs smaller noncertification diagnostics.
The independent TLS, QUIC, host and security gates retain their own
immutable workflow-run evidence in the canonical plan. In particular,
a green profile job or a green Sphinx build **must not** turn U15/U17
release performance, matched TLS or WebTransport certifications green.
