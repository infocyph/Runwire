Runtime and resource configuration reference
============================================

Configuration layers
--------------------

Do not mix the runtime driver, worker process policy, protocol limits and
outbound identity into one opaque option bag. Runwire places these
concerns on distinct value objects and immutable policies.

.. list-table:: Configuration ownership
   :header-rows: 1
   :widths: 26 39 35

   * - Object or surface
     - Governs
     - Typical review
   * - `RuntimeOptions`
     - Driver, OPcache mode, recycle, diagnostics, admission, host adapters
     - Capability and process model
   * - `Server`
     - HTTP bind, workers, connection limits, TLS, H3 listener
     - Listener protocol security
   * - `StreamServer` / `DatagramServer`
     - TCP/Unix framing and datagram bindings
     - Socket lifecycle and message quotas
   * - `WorkerGroup`
     - Native bootstrap, number of workers, restart/recycle/identity
     - Master versus worker control
   * - `AutoscalePolicy`
     - Min/max, queue/CPU/p99 pressure, cadence, cooldown, step
     - Stable scaling and safe operator overrides
   * - `DialOptions` / `TlsClientOptions`
     - Outbound deadline, cancellation and peer verification
     - Trust roots, SNI, ALPN
   * - `ConnectionLimits` / `Http1Options` / `Http2Options`
     - Input/output and stream/header/body bounds
     - Slow peer and oversized requests
   * - `ProcessExecutor` / `AsyncFileSystem`
     - Off-reactor worker and queue limits
     - Worker exhaustion and cancellation
   * - `PoolIsolationKey`
     - Reuse isolation by origin, TLS identity, tenant and proxy policy
     - Credential boundary

Core runtime
------------

The public `RuntimeOptions` constructor accepts named settings for
`driver`, `opcache`, `workerRecycle`, `requestExecution`,
`admission`, `diagnostics`, `privilegeDrop`, `fpm`,
`frankenPhp`, `roadRunner`, `swoole`, `applicationLifecycle`
and `reload`. Defaults are part of the source contract and may change
before 3.0 is frozen; inspect :doc:`api-reference-3.0` and
`src/RuntimeOptions.php` in the exact revision being deployed.

.. code-block:: php

   <?php
   use Infocyph\Runwire\Runtime;
   use Infocyph\Runwire\RuntimeOptions;

   $runtime = Runtime::create(new RuntimeOptions());
   // Bind one or more validated Server/StreamServer/DatagramServer
   // instances before $runtime->run().

A driver selected automatically must only expose capabilities supported by
the actual PHP process and host. Choosing the native driver does not create
ext-quic, sockets, POSIX signals or an alternate Swoole reactor.

Inbound server limits
---------------------

The `Server` model includes a server `name`, network `address`,
`workers`, `workerConnectionLimit`, `listener`, `connection`,
optional `tls` and readied-worker/shutdown timeouts. Protocol-specific
options are separate; an HTTP/1.1 parser budget is not the same thing
as a QUIC flow-control budget.

Size budgets should account for at least:

* concurrent accepted sockets per worker and TCP accept backlog;
* maximum pending TLS handshakes and handshake time;
* received header bytes, body bytes, chunk/decompression limits;
* H2 SETTINGS, HPACK dynamic table, active streams and connection credit;
* H3/QPACK blocked streams, stream deadlines and transport limits;
* WebSocket frame/message length and connection lifetime;
* file job count, subprocess IPC size and queued work;
* producer write-buffer and consumer read-buffer upper bounds.

A capacity value must be enforced at admission, not only in metrics.
When rejecting a request, avoid allocating another large buffer to
construct an error.

Autoscale policy with real native workers
-----------------------------------------

`AutoscalePolicy` has verified constructor defaults: `minWorkers=1`,
`maxWorkers=8`, `scaleInCpuRatio=0.30`,
`scaleOutCpuRatio=0.75`, `scaleOutQueueDepth=16`,
`scaleOutP99Milliseconds=250.0`,
`scaleInSuccessfulRps=1.0`, `sampleSeconds=10.0`,
`cooldownSeconds=30.0`, `maxStepPerWindow=1` and
`workerOverride=null`. These are defaults, not production sizing
recommendations.

.. code-block:: php

   <?php
   use Infocyph\Runwire\Supervisor\AutoscalePolicy;

   $policy = new AutoscalePolicy(
       minWorkers: 2,
       maxWorkers: 8,
       scaleOutCpuRatio: 0.75,
       scaleOutQueueDepth: 32,
       sampleSeconds: 5.0,
       cooldownSeconds: 20.0,
       maxStepPerWindow: 1,
   );

Native `Supervisor::autoscale($groupName, $policy, $sample)` accepts a
sample callback reporting `cpuRatio` (float), `queued` (int),
`successfulRps` (float) and `p99Milliseconds` (float). Missing or
invalid telemetry cannot authorize a blind fork or retirement. Sampling
uses hysteresis; the operator override fixes the active worker target
within configured bounds. Scale-in must not retire the last admissible
worker or duplicate a currently owned slot.

Outward connections and limits
------------------------------

Direct numeric TCP/Unix dialing uses `Dialer` with its pending limit.
Hostnames use `AsyncDnsResolver` outside the loop plus
`HappyEyeballsDialer` (bounded IPv6/IPv4 staggering).
Every outbound TLS operation needs an exact original host identity and
explicit validation policy. Connection reuse is an independent decision
based on `PoolIsolationKey`; a successful TLS handshake alone does not
authorize tenant-crossing pooled reuse.

Host configuration and portability
----------------------------------

A prefork supervisor and a host worker are different capabilities.
RoadRunner/FrankenPHP and FPM have host-specific lifecycle policies;
Swoole and OpenSwoole own their reactors. In hosting environments where
forking or ext-quic is unavailable, use only the supported portable or
host-owned features. See :doc:`production-operations-3.0` and
:doc:`host-compatibility-3.0`.

Validation and rollout
----------------------

Before changing a live value:

1. Record runtime/driver, PHP version, extensions and exact source SHA.
2. Validate the PHP options object; never ignore constructor exceptions.
3. Reproduce min/max load and a slow-peer case in staging.
4. Confirm bounded rejection and metrics under saturation.
5. Confirm graceful drain and SIGKILL behavior separately.
6. Check RSS/FD, p99 and every response error against the baseline.
7. Deploy a canary and retain a reversible configuration change.

Use :doc:`performance-qa-handbook-3.0` for comparison methodology
and :doc:`api-reference-3.0` for exact method signatures.
