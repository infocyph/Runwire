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
     - Inbound HTTP/1.1 upgrade, masking, fragmentation, ping and close tests
     - Outbound WebSocket client and H2/H3 extended CONNECT are not yet available
   * - WebTransport
     - Planned H3 session, stream, deadline and datagram ownership
     - No advertised end-to-end WebTransport client or server; native QUIC datagram capability is unavailable
   * - Asynchronous file I/O
     - Bounded off-loop subprocess read/write/stat/rename/unlink, development-scoped Fiber adapter and file-to-network bridge
     - No persistent file handles, symlink-safe sandbox, crash-atomic fsync, platform native AIO or final throughput certification
   * - Autoscaling
     - Supervisor crash restart and generation rotation with existing bounded diagnostics
     - Adaptive scaling and sustained real mixed-protocol stop/soak acceptance remain in development

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
