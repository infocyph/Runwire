Unified asynchronous runtime architecture
=========================================

The Runwire 3.0 development branch implements a shared model for
incoming and outgoing connection owners, nonblocking socket readiness,
protocol-specific sessions, coroutine-scoped cancellation and bounded
off-reactor file/DNS operations.

A successfully accepted connection and an outbound dial result both
have one owner. Their deadlines, buffer budget, callbacks and
shutdown rules may differ; they cannot be collapsed into an
unstructured global socket registry.

.. code-block:: text

   inbound listener             outbound request
        |                             |
   accepted connection          DNS -> dial -> TLS verification
        |                             |
        +------> established stream <-+
                       |
              protocol-specific session
                       |
            bounded application admission
                       |
               response/body stream
                       |
              drain and owner release

Native master/worker topology
-----------------------------

Native `Supervisor` launches worker groups with explicit readiness,
restart budgets, rolling reload, recycling and scale in/out. The
native `SupervisorAutoscaler` makes actual controlled fork/retire
decisions using valid CPU, queued workload, successful RPS and p99
samples, monotonic hysteresis and cooldown. Native autoscaling **is
implemented and C3-tested**; final U15 saturation, 1800-second
soak and frozen release benchmarks remain separate requirements.

The child owns its event loop and active connections. A bound TCP
listener may be inherited before it becomes active, but an active
listener and outgoing dialer are PID-owned. Cross-process mutation
of inherited active watchers, dials, sessions and pools is prohibited.

Portable and host-owned topology
--------------------------------

Select can drive portable nonblocking sockets without POSIX
supervision. Host drivers integrate with the runtime supplied by
FPM, RoadRunner, FrankenPHP or Swoole/OpenSwoole. They must not
implicitly create new masters or conflicting reactors for one
request.

Off-reactor filesystem and DNS
------------------------------

`ProcessExecutor` uses bounded separate PHP CLI worker processes
and nonblocking IPC pipes for potentially blocking operations.
The filesystem facade does not advertise kernel-native acceleration,
durable rename or filesystem sandboxing. On a host without process
capability, fail visibly instead of blocking the reactor.

Security and unsupported features
---------------------------------

A reused connection needs exact origin, TLS peer and ALPN identity,
tenant and proxy-route isolation. Verified HTTPS over a cleartext
HTTP CONNECT tunnel is separate from the unavailable TLS-over-TLS
HTTPS-proxy nesting. WebTransport remains excluded from Runwire 3.0
because the available PHP native QUIC runtime does not provide the
required DATAGRAM capability. H3 itself remains supported on
capable native backends.

For full details consult :doc:`handbook-3.0`,
:doc:`runtime-lifecycle-3.0`, :doc:`configuration-reference-3.0`
and :doc:`supervision-handbook-3.0`. This document is the
high-level architecture view, not the entire implementation contract.


Follow one request through the runtime
--------------------------------------

**Step 1, choose the owner.** A native CLI runtime may create a
listener; a hosted runtime (FPM, RoadRunner, FrankenPHP or Swoole)
already has an external request or event owner.

**Step 2, admit and parse.** One listener accepts bytes, but a single
H2 connection can contain many independently canceled streams.
The application receives only validated request state after protocol
admission, not raw arbitrary bytes as a trusted request.

**Step 3, perform application work.** Readiness callbacks stay short;
nonblocking DNS/TCP operations retain their own deadlines; blocking
filesystem operations run in bounded worker processes.
Coroutines give structure to suspension/cancellation, not extra
CPU cores or transparent conversion of blocking calls.

**Step 4, write with pressure.** An accepted write is not proof the
peer consumed its bytes. Bound queued data and wait for drain or
cancel the operation instead of retaining unlimited response buffers.

**Step 5, retire exactly once.** Request scopes, sockets, pending DNS
jobs, worker generation and reactor watchers must have separate owners.
A normal request completion should not close an unrelated pooled
connection; a worker shutdown must stop admissions and reap its children.

**Walkthrough:** run ``tests/fixtures/portable_native_acceptance.php``
for a real native single-process example, then
``tests/WorkerOwnedNetworkDrainTest.php`` to verify ownership
during shutdown. The complete beginner HTTP example is in
:doc:`feature-workflows-3.0`.
