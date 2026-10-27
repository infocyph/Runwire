C4 reproducible performance laboratory
======================================

Release gate, not a marketing benchmark
----------------------------------------

The C4 U15 program has **ten** distinct performance tasks.
Do not treat a 23-second smoke, a 120-second C3 autoscale test or a
five-second protocol diagnostic matrix as final 3.0 performance
certification. The published comparator is Runwire 2.1.2 at
immutable SHA `7e9bf20ad0737a187dfed77b723791b141b36378`.
Freeze the 3.0 candidate SHA before measuring.

Every accepted result must include a complete environment and load
generator provenance: PHP binary and config, OS/kernel, CPU and quotas,
memory, OPcache/GC, extensions, protocol/ALPN, trust configuration,
worker counts, exact request/response payload, concurrency, samples,
errors, CPU/RSS/FD and p50/p95/p99. Measure **successful responses**,
not connection attempts or client-side writes.

.. list-table:: C4 U15 evidence checklist
   :header-rows: 1
   :widths: 20 45 35

   * - Task
     - Evidence required
     - Insufficient substitute
   * - U15.01
     - Real cold/warm handler, context, loop and syscall attribution
     - Single no-op callback timing
   * - U15.02
     - Warmed concurrency sweep, success RPS, p50/p95/p99, CPU/RSS/FD
     - One peak-RPS sample
   * - U15.03
     - Tracing on/off comparison with same controlled workload
     - Assumption that logging is free
   * - U15.04
     - Separate H1 plaintext/TLS, H2 HPACK and H3 QPACK/QUIC hot paths
     - A synthetic H1-backed H3 result
   * - U15.05
     - Mixed slow receiver, reset/cancel and recycle correctness
     - A handshake that merely succeeds
   * - U15.06
     - Sequential/overlapped outbound and valid connection-pool benefit
     - An in-memory mock connector
   * - U15.07
     - Independent real H1/H2/H3 and supported WebSocket client/server peers
     - Only a client or a fake protocol
   * - U15.08
     - Process file I/O + socket + autoscale interleaving and loop fairness
     - Filesystem-only throughput
   * - U15.09
     - Five paired 180s old/new trials, CV and strict p99/RSS/RPS gates
     - Five-second AUTO/FIXED diagnostics
   * - U15.10
     - Full 1800-second process-tree CPU/RSS/FD/GC/OPcache soak
     - Parent-only memory and 120-second smoke

Attribution and concurrency sweeps
------------------------------------

Use separate cold and warmed phases. Measure handler dispatch,
context creation, readiness loop polling, syscall read/write,
TLS handshake and protocol framing where applicable.
Report both absolute time and percentage of total latency.
Trace-on/off tests must hold logger settings and sink constant.

Sweep concurrency through saturation, not only one favorable point.
Confirm load-generator headroom on a separate measured process/host;
otherwise generator saturation can masquerade as server throughput
or artificially improve p99.

Protocol measurements
---------------------

The repository provides real protocol diagnostic runners:

.. code-block:: console

   bash benchmarks/adaptive_http12_certify.sh 5 benchmark-results/adaptive-http12
   bash benchmarks/adaptive_http3_certify.sh 5 benchmark-results/adaptive-http3

The value `5` above is a **five-second phase** for short diagnostics,
not a U15.09 full matched pair. HTTP/3 requires a functional native
QUIC extension and independent aioquic reference client. A skipped
H3 test on an incapable host does not certify H3.

For simultaneous protocol shutdown/cancellation, inspect and run
`benchmarks/mixed_native_lifecycle.py` in its provisioned native
interop CI environment. It checks independent H1/H2/H3/TCP/UDP peers
and accepted stream bodies, not a fake packet echo.

Outbound and filesystem mixed work
-----------------------------------

Separate cold dial/DNS/TLS, established-pool hits, sequential exchange
and genuine overlapped I/O. Reuse must honor original origin,
tenant, TLS validation, ALPN, proxy route and policy revision.
Credential or identity violations invalidate any apparent speedup.

Process workers execute bounded off-reactor filesystem operations.
Measure executor worker count, pending queue, cancellation and
memory along with socket latency and worker autoscale changes:

.. code-block:: console

   php .ci/c3_filesystem_smoke.php
   php .ci/c3_autoscale_http_traffic.php
   php .ci/c3_supervisor_soak.php

These three commands are C3 diagnostics, not the complete U15.08
or U15.10 release proof.

Five paired 180-second baseline comparisons
--------------------------------------------

Repository matched-pair runner
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

The repository already provides a guarded end-to-end comparator:
``benchmarks/matched_release_certification.sh``. It expects
**three** arguments: a complete published 2.1.2 source checkout,
a complete frozen 3.0 candidate checkout and an output directory.
Do not pass the same source directory for both versions.

For example, from the Runwire repository root, substitute a reviewed
immutable 3.0 SHA for ``<candidate-SHA>``:

.. code-block:: console

   git worktree add --detach /tmp/runwire-2.1.2 7e9bf20ad0737a187dfed77b723791b141b36378
   git worktree add --detach /tmp/runwire-3.0 <candidate-SHA>
   cd /tmp/runwire-2.1.2
   composer install --no-dev --no-interaction --prefer-dist --no-progress
   cd /tmp/runwire-3.0
   composer install --no-dev --no-interaction --prefer-dist --no-progress
   RUNWIRE_CANDIDATE_BUILD=<candidate-SHA> bash benchmarks/matched_release_certification.sh /tmp/runwire-2.1.2 /tmp/runwire-3.0 benchmark-results/c4-matched

The shell harness validates OPcache enabled for CLI, Linux CPU affinity
using distinct physical client/server cores, exact runtime hashes, a
30-second warmup per server, 5 alternating baseline/candidate runs
lasting 180 seconds each, full successful-response correctness and
the candidate-versus-baseline regression comparison. It emits separate
raw trial JSON, both summaries and ``comparison.json``; a failed
correctness/variance/budget gate exits nonzero.

Do **not** substitute diagnostic 5-second scripts, a shared saturated
client/server CPU, a baseline from the wrong package revision or an
incomplete trial. The current runner covers its **specified H1
baseline workload**; additional H1/TLS, H2, H3 and WebSocket real
counterpart profile cells remain independent U15 gates. A successful
H1 matched run is not full C4 certification.


For each required protocol/workload cell:

#. Freeze both revisions and publish the exact source and QA identities.
#. Use matched PHP, extensions, hardware and server policies.
#. Warm both versions before entering the measured phase.
#. Alternate comparison order to reduce time/thermal drift.
#. Capture **five** complete old/new trials of at least 180 seconds.
#. Verify every protocol response, body length and accepted request.
#. Record individual p99, CPU/RSS/FD, correct RPS and error rates.
#. Require the coefficient of variation for **both** versions below
   **2.5 percent**, without discarding bad trials.
#. Apply the frozen release p99/RSS/RPS regression budgets.
#. Archive the raw logs, script hashes and measurement artifacts.

A convenience script that accepts 180 as a duration is **not** on its
own an old-versus-new paired certification. The baseline 2.1.2
source must actually be checked out and exercised.

Continuous 1800-second process-tree soak
-----------------------------------------

Run a full **1800 seconds** at representative network and file load,
with repeated worker scale, recycle, pending dial cancellation and
protocol peers. Sample the **entire process tree**, including
executor subprocesses, native worker RSS, CPU, FD, GC and OPcache.
A stable parent heap is insufficient when child processes leak.
After termination, check for zero stale worker PIDs and watchers.

Accept only when all mandatory workloads have complete results and
no unbounded resource growth or data loss. When a capability such
as native QUIC is unavailable, mark that cell **not certified**;
do not invent fallback performance.

Reproducibility record
----------------------

.. code-block:: text

   experiment id and UTC time
   baseline SHA / candidate SHA
   server PHP binary and configuration
   loaded extensions and QUIC backend
   OS/kernel/CPU/memory/cgroup limits
   generator version, location and spare headroom
   protocol, ALPN, payloads, concurrency and sample duration
   successful RPS, errors, resets, completed body checks
   p50/p95/p99, process-tree CPU/RSS/FD/GC/OPcache
   warmup, trial order, five matched data points and both CV values
   anomaly notes, raw logs, artifact hashes and reviewer disposition

See :doc:`performance-qa-handbook-3.0` and
:doc:`operations-exercises-3.0`. U15 completion does not by itself
authorize a C5 release, tag or merge.
