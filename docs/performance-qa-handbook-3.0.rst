Performance methodology, benchmarking and QA evidence
=====================================================

Separate diagnostic measurements from release certification
-----------------------------------------------------------

Native smoke tests, five-pair H1/H2/WebSocket comparisons and sustained
1800-second resource-soak tests measure distinct performance properties.
Short-lived native supervisor smoke runs, filesystem microbenchmarks,
a reproducible protocol diagnostic matrix and a final frozen
five-pair baseline study answer different questions.

Never promote a diagnostic throughput number to a release claim
without matched workload, equivalent host resources, successful
response accounting and independently verified correctness.

Test pyramid
------------

.. list-table:: QA layer
   :header-rows: 1
   :widths: 29 39 32

   * - Layer
     - What it establishes
     - What it does not
   * - Source and PHPForge QA
     - Compile, unit invariants, static checks and style rules
     - Real network throughput
   * - Portable native probes
     - Real loopback TCP/Unix/UDP without prefork assumptions
     - Native QUIC support on every host
   * - Real hosted runtime
     - Host-owned request worker correctness
     - Native supervisor behavior under all signal combinations
   * - Native process lifecycle smoke
     - SIGKILL/reload/scale/drain, short/120s resource bounds
     - Final 1800-second release resource plateau
   * - Independent H2/H3 peer
     - External reference protocol interoperability and adversarial cases
     - Guaranteed availability without required PHP extensions
   * - Diagnostic protocol matrix
     - Relative AUTO/FIXED tuning behavior
     - Frozen 2.1.2-versus-3.0 release claim
   * - U15 matched baseline
     - Five 180-second paired runs with variance/correctness gates
     - Future runtimes and workloads not represented by the run
   * - U17 frozen certification
     - Exact immutable source review and entire release gate set
     - Automatic permission to tag or publish

Frozen baseline protocol
------------------------

The published comparison is Runwire **2.1.2** at documented immutable
commit `7e9bf20ad0737a187dfed77b723791b141b36378` versus one
**frozen** 3.0 candidate SHA. Run the same PHP major/minor,
extensions, CPU/RAM quotas, network topology, TLS policy, request
payload distribution and independent peers. Use controlled warmup and
record environmental metadata with every run.

For each matched protocol/workload cell, capture at least:

* attempted, admitted, completed, failed and cancelled requests;
* successful requests/second with clear time-window boundaries;
* p50, p95 and p99 latency with full request completion semantics;
* CPU user/system, peak/process-tree RSS and open file descriptors;
* reactor callback lag, queue depth and connection counts;
* absolute and relative measurement variability;
* peer correctness, TLS/ALPN result and any retried requests;
* git SHA, tooling versions, OS/kernel, payload sizes and tuning flags.

The release methodology requires **five paired 180-second comparisons**,
both baseline and candidate throughput coefficient-of-variation below
**2.5%**, and strict p99, RSS, successful RPS and correctness gates.
Do not average away one failed peer, restart a bad trial silently
or count rejected requests as successes.

Resource soak: 1800 seconds
---------------------------

The final process-tree soak lasts 1800 seconds and checks long-running
CPU/RSS/FD, cache/GC/OPcache effects, repeated worker recycle,
connection teardown and absence of stale processes. A C3 120-second
native supervisor soak is valuable but **is
not** the release soak. Sample the full process tree rather than only
the parent PHP heap.

A flat parent heap is insufficient when worker children leak native
extension memory or file descriptors. Sample at consistent intervals,
capture per-child peak RSS, count live PIDs before/after, and verify
that each admitted transaction still received a correct response.

Load-generator methodology
--------------------------

Keep the generator separate from the measured server and show spare
CPU/RAM/network headroom; otherwise generator saturation can appear
as a server throughput plateau. Warm caches and TLS sessions
consistently, but also retain cold-path security tests. Alternate
trial ordering to avoid thermal/time-of-day effects. Preserve raw
samples and all failure diagnostics; summaries must be reproducible
from the raw evidence.

For outbound performance, benchmark both sequential and overlapped
DNS/dial/TLS/HTTP operations. Measure a true connection-pool hit
separately from a new connection; ensure the reuse key does not cross
tenant/identity boundaries.

Reproducible repository gates
-----------------------------

.. code-block:: console

   php tests/smoke/native_supervisor_soak.php
   php tests/smoke/native_autoscale_http_traffic.php
   php tests/smoke/native_filesystem_smoke.php

The repository's active workflow definitions are:

* ``.github/workflows/security-standards.yml``
* ``.github/workflows/source-audit.yml``
* ``.github/workflows/documentation.yml``
* ``.github/workflows/portable-native.yml``
* ``.github/workflows/http2-independent-reference.yml``
* ``.github/workflows/real-host-acceptance.yml``
* ``.github/workflows/swoole-coroutine.yml``
* ``.github/workflows/release-candidate.yml``
* ``.github/workflows/release-certification.yml``

Read each script's arguments and prerequisite extensions before
running it. Workflow names are not claims that every required release
comparison has already been executed on the current SHA.

Acceptance and evidence policy
------------------------------

A task is QA-accepted only when its exact acceptance contract,
independent test evidence and required full quality gates pass. For
a reported result, publish links to source SHA and the immutable CI
run, raw artifacts, workload definition, threshold and failure
counts. Keep diagnostic findings, accepted feature tests and
release-certification decisions in separate categories.

See :doc:`performance-security-3.0` for feature-specific methods,
:doc:`observability-handbook-3.0` for telemetry,
and :doc:`migration-playbook-3.0` for rollout risk.
