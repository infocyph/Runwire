Host and runtime capability matrix
==================================

Runwire supports multiple host-owned and native execution modes.
The host determines worker and reactor ownership, while PHP extensions
and permissions determine optional protocol support.

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
     - The server owns request dispatch. Persistent pooled outgoing file/socket resources require explicit surviving worker ownership and cleanup.
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
     - Available capability
     - Limits and prerequisites
   * - HTTP/1.1, HTTP/2 and HTTP/3 servers
     - Existing request contracts and server regressions; native H3 interoperates with an independent peer where QUIC is available
     - Release throughput, full hostility matrix and cross-protocol churn remain gated
   * - HTTP clients
     - Verified H1/H2 clients and native QUIC-dependent H3 outbound implementation with independent protocol acceptance
     - Exact-source H2/WS paired comparison and full 1800s resource certification accepted; unified HTTP pooling remains separate
   * - WebSocket
     - Inbound HTTP/1.1 upgrade and experimental outbound ws/wss TLS upgrade with shared masking, fragmentation and close
     - Outbound ws/wss client tests pass on supported paths; H2/H3 extended CONNECT remains unavailable
   * - Asynchronous file I/O
     - Bounded off-loop subprocess read/write/stat/rename/unlink, development-scoped Fiber adapter and file-to-network bridge
     - No persistent file handles, symlink-safe sandbox, crash-atomic fsync, platform native AIO or final throughput certification
   * - Autoscaling
     - Real native fork/retire autoscale, crash restart, readiness and rolling generations
     - Native scaling, tested with mixed worker/resource workloads

Native supervision, recycling and autoscaling
---------------------------------------------

The native supervisor uses bounded worker groups and a deliberately explicit
lifecycle. The existing Supervisor and WorkerGroup APIs select a fixed worker
count; the published 2.1.2 contract does not include the new 3.0 native
CPU/queue/p99-driven automatic scaling, but 3.0 source now implements
and supports real scale-out and graceful scale-in.

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

Native supervision tests cover crash restart, bounded backoff, generation
rotation, mixed real H1/H2/H3/TCP/UDP stop-during-traffic, actual native
autoscale and process-tree cleanup. Use the native supervisor smoke tests and sustained resource benchmarks
to validate scaling and process-tree cleanup in your environment.

Proof boundary and operator guidance
------------------------------------

The GitHub Actions workflows document each tested source SHA:

* Security & Standards: PHPForge Pest, code-style, analyzers, independent native QUIC and HTTP/3 tests.
* Portable Native: PHP 8.4/8.5 without PCNTL, optional OpenSSL negative cases, and PHP 8.4 ext-event accelerated checks.
* Real Hosted Runtime Acceptance: actual FPM, FrankenPHP worker and RoadRunner request sequences on both PHP versions.
* Swoole/OpenSwoole Coroutine: live host reactor and outbound socket verification.
* Documentation and Release Candidate: strict Sphinx pages and standalone package archive installation.

Host capability claims must be verified against the deployed source,
PHP extensions and actual worker lifecycle. Do not assume that supporting a host implies that
all protocol clients, optional extensions, async disk features or worker
autoscaling are available on that host.

Native autoscale policy (implemented on capable native hosts)
-------------------------------------------------------------

``Supervisor\AutoscalePolicy`` is a native worker scaling policy, integrated
with the running Supervisor and its worker generation lifecycle. Its defaults bound workers to 1..8,
CPU scale-in below 30 percent, scale-out above 75 percent, queue depth 16,
p99 threshold 250 ms, and low successful RPS at 1/s. Samples span 10 seconds,
cooldown at least 30 seconds, and changes at most one worker per window.

An operator-supplied fixed worker count within configured bounds overrides
both effective minimum and maximum. All CPU/latency/rate inputs must be
finite, with strictly ordered hysteresis thresholds; cooldown must be at
least one sample window. Host-owned process managers never inherit these
native worker policies. Automated decisions, readiness and graceful
scale-in can be tested with live worker samples; a load soak does not
prove compatibility with unsupported hosts.

Pure autoscale decision model and native integration
----------------------------------------------------

``Supervisor\AutoscaleDecisionEngine`` computes a bounded desired worker
count from one CPU/queue/successful-RPS/p99 observation and a policy. It
refuses missing or non-finite inputs, applies fixed operator overrides before
cooldown, honors lower/upper limits and restricts steps. Scale-out is
suggested for queue or active-request pressure; scale-in requires an empty
queue and both low CPU and low successful RPS. Hysteresis and cooldown
prevent rapid oscillation when signals cross thresholds.

The decision function itself remains pure: it does not directly fork
or terminate workers. The native Supervisor now consumes controller
observations and performs real bounded spawn/retire and readiness/drain.
Host-owned runtimes still do not inherit those process controls.
For production, verify autoscale decisions, readiness and graceful drains
under representative traffic.


Optional PSR-7/17 interoperability boundary
-------------------------------------------

`Interop\\Psr\\PsrMessageBridge` can exchange validated header metadata,
construct bodyless PSR-7 requests and construct PSR-17 response metadata
when the caller installs the standard `psr/http-message` and
`psr/http-factory` packages. These are **suggested** dependencies,
not required runtime packages. A PSR stream's `read()` may block,
so the adapter fails closed on nonempty or unbounded request bodies;
it never reads PSR stream bodies on the Runwire reactor.

An opt-in bounded `Interop\\Psr\\PsrMiddlewareAdapter` also runs a PSR-15
middleware stack on **host-owned synchronous request boundaries only**,
with explicit request/response ownership and a 32-middleware cap.
Do not invoke arbitrary synchronous PSR middleware from native Runwire
reactor callbacks. A synchronous PSR-18 HTTP client is **not** provided;
that contract requires a separate off-loop, transport and body-stream
integration with its own acceptance suite.
`tests/PsrMessageBridgeTest.php` uses an independent Nyholm PSR-17
implementation as an optional interoperability test fixture.


A decision walkthrough for native versus hosted PHP
---------------------------------------------------

**Example A: standalone CLI daemon.** Choose native runtime only when
the process may bind listening ports and own its event loop. If PCNTL
is missing, use portable single-process mode rather than pretending
there is a prefork supervisor. Run
``tests/fixtures/portable_native_acceptance.php`` for a real peer.

**Example B: PHP-FPM HTTP request.** FPM owns the worker process and
reuses it according to its configured lifecycle. A Runwire callback
must finish or cancel request-scoped resources; do **not** start a
second long-running native master inside a request handler.

**Example C: RoadRunner/FrankenPHP.** Host workers are long-lived,
which makes request-to-request state isolation important. Give
each accepted request an explicit scope, clean up resources on
completion and test multiple sequential requests on the same
host-owned worker.

**Example D: Swoole/OpenSwoole.** The host's reactor is the event
owner; attaching a competing loop or closing an external reactor
from a Runwire callback violates ownership. Run the dedicated
Swoole/OpenSwoole interoperability workflow.

**Limitations:** host compatibility is not proof of native QUIC
capability or background-process permissions. Compare actual extension
availability, response semantics and worker ownership per host
rather than copying a native deployment recipe into FPM.
