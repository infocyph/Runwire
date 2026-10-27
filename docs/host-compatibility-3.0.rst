Host and runtime capability matrix (3.0 development)
======================================================

.. important::

   This page describes Runwire 3.0 development-branch behavior and actual
   testing boundaries, **not** a released Runwire 3.0 feature guarantee.
   Runwire 2.1.2 remains the published stable version.

Host deployment and resource ownership
--------------------------------------

.. list-table:: Verified capabilities and explicit limitations
   :header-rows: 1
   :widths: 18 35 47

   * - Execution host
     - Verified existing capability
     - Ownership and portability limitations
   * - Native Select loop
     - TCP, HTTP/1.1, HTTP/2, bounded worker I/O, and request scopes on PHP 8.4 and 8.5
     - Prefork/supervision requires PCNTL and POSIX. Without those extensions, use the supported single-process mode.
   * - Optional ext-event loop
     - ext-event descriptor tests and HTTP/1 worker byte-budget tests in the accelerated PHP 8.4 CI lane
     - ext-event is optional and not required for install. Equivalent coverage across every native protocol or PHP 8.5 is not yet certified.
   * - Optional native QUIC
     - TLS 1.3 and ALPN verified with independent QUIC peers when ext-quic and the supported crypto stack are present
     - Without ext-quic, QUIC/H3 must fail as unavailable. Native RFC 9221 datagrams, path migration and 0-RTT application dispatch explicitly remain unsupported by the current backend.
   * - PHP-FPM
     - Actual FastCGI requests through the host-owned process on PHP 8.4 and 8.5
     - Request scopes end with the request; no assumption of inherited persistent application connections or background I/O.
   * - FrankenPHP worker
     - Real persistent process and separate request contexts on PHP 8.4 and 8.5
     - Worker lifetime differs from request lifetime; socket/file reuse must have an explicit surviving owner.
   * - RoadRunner worker
     - Real PHP 8.4 and 8.5 worker processes handle multiple distinct requests with persistent worker identity
     - The server owns request dispatch. Pooled outgoing file/socket cleanup across requests is still undergoing U13.04 acceptance.
   * - Swoole and OpenSwoole
     - Real host-owned coroutine reactors and outgoing TCP dial operations on PHP 8.4 and 8.5
     - Do not start or terminate another reactor for one request; use the host adapter and its cancellation owner.
   * - Portable PHP CLI without PCNTL
     - Select-based transport and TLS-dependent paths tested without fork/signal functions
     - No prefork or signal-driven autoscaling promise; subprocess file/DNS work separately requires proc_open and process-pipe support.

Transport and workload features
-------------------------------

.. list-table:: Protocol and I/O maturity
   :header-rows: 1
   :widths: 24 36 40

   * - Feature
     - Development source reality
     - Not yet certified for 3.0 release
   * - HTTP/1.1, HTTP/2 and HTTP/3 servers
     - Existing request contracts and server regressions; native H3 interoperates with an independent peer where QUIC is available
     - Release throughput, full hostility matrix and cross-protocol churn remain gated
   * - HTTP clients
     - Version-policy interface, in-progress bounded HTTP/1.1 serializer and parser
     - Connected H1 client API, H2/H3 client implementations and outbound client benchmarks are not yet complete
   * - WebSocket
     - Inbound HTTP/1.1 upgrade and experimental outbound ws/wss TLS upgrade with shared masking, fragmentation and close
     - Outbound client QA is not yet accepted; H2/H3 extended CONNECT remains unavailable
   * - Asynchronous file I/O
     - Bounded off-loop subprocess read/write/stat/rename/unlink, development-scoped Fiber adapter and file-to-network bridge
     - No persistent file handles, symlink-safe sandbox, crash-atomic fsync, platform native AIO or final throughput certification
   * - Autoscaling
     - Supervisor crash restart and generation rotation with existing bounded diagnostics
     - Adaptive scaling and sustained real mixed-protocol stop/soak acceptance remain in development

Native supervision, recycling and autoscaling
---------------------------------------------

The native supervisor uses bounded worker groups and a deliberately explicit
lifecycle. The existing Supervisor and WorkerGroup APIs select a fixed worker
count; no independently verified CPU/RPS/p99-driven automatic scale-out or
scale-in policy is part of the published stable contract.

* RestartPolicy limits restarts in a sliding window and applies bounded
  exponential backoff. Exhaustion is terminal rather than an infinite crash loop.
* ReloadPolicy controls replacement surge, minimum availability, readiness
  timeout and graceful drain. A new generation must be ready before retiring
  the previous healthy workers.
* WorkerRecyclePolicy can bound request count, lifetime or memory, with jitter
  to avoid synchronized replacements. Zero thresholds disable those triggers.
* Supervisor status, worker lifecycle events and group admission expose
  explicit operational control. A stopped request does not gain permission to
  leave an orphaned outgoing connection or executor job.

.. code-block:: text

   Native PCNTL/POSIX owner
     |
     +--> Supervisor --> WorkerGroup count / restart budget
             |                  |
             |                  +--> readiness / generation rotation
             |                          |
             |                          +--> old generation drains
             |
             +--> status and lifecycle events
             +--> bounded recycle triggers
             +--> stop / terminate child process tree

Portable PHP without PCNTL cannot fork or install native signal handlers.
PHP-FPM, FrankenPHP, RoadRunner, Swoole and OpenSwoole already have host
process/reactor lifecycle managers; Runwire request adapters must use their
native ownership instead of attempting a second fork supervisor in-process.
Worker-owned pools may survive requests **only** after explicit ownership
handoff, with cleanup performed by the surviving host/worker owner.

Native supervision tests validate crash restart, bounded backoff, generation
rotation and basic process reaping. Full mixed H1/H2/H3/TCP/UDP stop-during-
traffic, real autoscale policy, saturation recovery and final process-tree
soak remain separate unaccepted 3.0 milestones.

Proof boundary and operator guidance
------------------------------------

The GitHub Actions workflows document each tested source SHA:

* Security & Standards: PHPForge Pest, code-style, analyzers, independent native QUIC and HTTP/3 tests.
* Portable Native: PHP 8.4/8.5 without PCNTL, optional OpenSSL negative cases, and PHP 8.4 ext-event accelerated checks.
* Real Hosted Runtime Acceptance: actual FPM, FrankenPHP worker and RoadRunner request sequences on both PHP versions.
* Swoole/OpenSwoole Coroutine: live host reactor and outbound socket verification.
* Documentation and Release Candidate: strict Sphinx pages and standalone package archive installation.

A green prior-source test is not proof of untested features added after that
source SHA. The canonical 3.0 roadmap and development plan remain the final
acceptance authorities. Do not assume that supporting a host implies that
all protocol clients, optional extensions, async disk features or worker
autoscaling are available on that host.

Proposed autoscale policy (not enabled)
---------------------------------------

``Supervisor\AutoscalePolicy`` is a development-only native worker scaling
configuration, not a running controller. Its defaults bound workers to 1..8,
CPU scale-in below 30 percent, scale-out above 75 percent, queue depth 16,
p99 threshold 250 ms, and low successful RPS at 1/s. Samples span 10 seconds,
cooldown at least 30 seconds, and changes at most one worker per window.

An operator-supplied fixed worker count within configured bounds overrides
both effective minimum and maximum. All CPU/latency/rate inputs must be
finite, with strictly ordered hysteresis thresholds; cooldown must be at
least one sample window. Host-owned process managers never inherit these
native worker policies. Automated decisions, readiness, graceful scale-in,
and 30-minute saturation soak remain separate incomplete U12 gates.

Pure autoscale decision model (not integrated)
----------------------------------------------

``Supervisor\AutoscaleDecisionEngine`` computes a bounded desired worker
count from one CPU/queue/successful-RPS/p99 observation and a policy. It
refuses missing or non-finite inputs, applies fixed operator overrides before
cooldown, honors lower/upper limits and restricts steps. Scale-out is
suggested for queue or active-request pressure; scale-in requires an empty
queue and both low CPU and low successful RPS. Hysteresis and cooldown
prevent rapid oscillation when signals cross thresholds.

This function is intentionally pure: **it does not create or retire a
single worker**, execute process signals, establish readiness, drain open
connections or claim that the host supports autoscaling. Native supervisor
wiring and real sustained stress remain U12.04/U12.09 open requirements.
