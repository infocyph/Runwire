Engineering handbook
====================

Overview and audience
---------------------

This handbook covers Runwire's execution model, APIs, supported
host environments and lifecycle contracts. It is intended for application
developers, platform engineers, library integrators, maintainers and
security reviewers.

Runwire provides a common asynchronous execution model for inbound and
outbound streams, event-driven networking, protocol handling, native worker
supervision and coroutine-scoped work. The guiding rule is that **each
resource has one lifetime owner**, and operations that could block the
reactor must not execute on the reactor thread.

Start with the :doc:`start` installation notice, the
:doc:`features` host/feature inventory and :doc:`tutorials-3.0` executable acceptance examples. For complete
what/why/when/how exercises see :doc:`feature-workflows-3.0`. This handbook is an orientation; the
:doc:`api-reference-3.0` is generated from the actual public declarations.

Documentation map
-----------------

.. list-table:: Find the correct guide
   :header-rows: 1
   :widths: 22 42 36

   * - Reader
     - Start here
     - Continue with
   * - New application developer
     - :doc:`configuration-reference-3.0`
     - :doc:`inbound-handbook-3.0` and :doc:`outbound-handbook-3.0`
   * - Framework or host adapter author
     - :doc:`runtime-lifecycle-3.0`
     - :doc:`concurrency-handbook-3.0` and :doc:`host-compatibility-3.0`
   * - Process and deployment operator
     - :doc:`supervision-handbook-3.0`
     - :doc:`production-operations-3.0` and :doc:`troubleshooting-handbook-3.0`
   * - Security reviewer
     - :doc:`security-handbook-3.0`
     - :doc:`connection-policies-3.0` and :doc:`protocols-3.0`
   * - Performance engineer
     - :doc:`performance-qa-handbook-3.0`
     - :doc:`performance-security-3.0`
   * - 2.1.2 maintainer upgrading to 3.0
     - :doc:`migration-playbook-3.0`
     - :doc:`migration-3.0` and :doc:`api-reference-3.0`

Execution architecture
----------------------

Runwire has three distinct execution ownership domains.

1. **Portable native loop.** A CLI application can use Select-based
   readiness, callbacks and request handlers without requiring a master
   process. This mode does not provide native prefork supervision.
2. **Native supervised processes.** The master manages worker generations,
   signals, readiness, autoscale decisions, crash restarts and worker drain.
   Each worker owns its event loop and active resources. Native prefork
   requires appropriate POSIX/PCNTL support.
3. **Host-owned runtimes.** FPM, FrankenPHP, RoadRunner, Swoole and
   OpenSwoole control some or all request/worker/reactor lifetimes. Runwire
   adapts to their existing ownership instead of spawning a second
   conflicting reactor.

.. code-block:: text

   Client / peer
       |
       +--> H1 / H2 server --> bounded request admission --> application
       |                                                   |
       +--> H3 / QUIC server --> stream and flow control ---+
       |                                                   |
       +--> TCP / Unix listener --> framed stream owner ----+
       |
       +--> UDP listener --> datagram admission --> handler

   Application -> coroutine scope -> cancellable network/file operations
                  |                |
                  |                +-> bounded process executor (off-loop I/O)
                  +-> loop-bound DNS / dial / TLS / protocol session

   Native parent -> supervisor -> worker groups -> generations -> drain
                        |             |
                        +-> metrics -> autoscale hysteresis -> controlled fork/retire

A successful outbound connection is never automatically a reusable one.
The identity of the origin, TLS trust policy, ALPN, tenant and proxy route
must agree with the consuming scope. Review :doc:`security-handbook-3.0`
before sharing a connection or credentials.

What async actually means
-------------------------

An asynchronous API must specify what waits and what makes progress.
A nonblocking socket is driven by reactor readiness; hostname lookups
must use an off-loop resolver; ordinary PHP filesystem functions remain
blocking unless delegated to bounded workers. Runwire does **not** claim
kernel-native filesystem AIO. Do not call arbitrary synchronous file
or DNS operations from a high-traffic network callback and interpret
the existence of an event loop as protection.

Backpressure, memory and admitted work
--------------------------------------

Memory safety is a flow-control decision, not only a configuration value.
Set explicit ceilings for connection counts, pending dial attempts,
executor queue depth, buffered bytes, header/body sizes and concurrent
protocol streams. When full, reject or suspend admission rather than
building unbounded lists. A write queue that never drains is not a
successful completed response.

An accepted request is not identical to a client attempting to connect.
Admission claims must be tied to protocol-level acceptance/response state;
normal shutdown should preserve already admitted work within the configured
grace period, while forced termination may abort it. Each benchmark must
state its definition of *accepted*, *completed* and *failed*.

Capability truthfulness
-----------------------

.. list-table:: Important boundaries
   :header-rows: 1
   :widths: 25 36 39

   * - Capability
     - Runtime implementation
     - Do not claim
   * - HTTP/1.1, HTTP/2
     - Inbound protocol support; distinct outbound client APIs
     - One magical client covering all protocol versions
   * - HTTP/3 / QUIC
     - Native QUIC-dependent H3 support and independent peer testing
     - Universal availability on stock PHP/cPanel
   * - WebSocket
     - H1 upgrade and opt-in outbound ws/wss client
     - H2/H3 extended-CONNECT WebSocket compatibility
   * - WebTransport
     - Explicitly excluded from 3.0
     - Any hidden compatibility shim or fake QUIC DATAGRAM mapping
   * - Filesystem
     - Bounded off-reactor process executor
     - Qualified native kernel-accelerated file backend
   * - HTTPS proxy nesting
     - Cleartext CONNECT to verified HTTPS origin
     - TLS-over-TLS through an already encrypted proxy

Documentation and verification
------------------------------

Commands and examples use the documented public APIs. When integrating into an
existing application, validate network protocol support, PHP extensions,
resource limits and shutdown behavior on the actual deployment host.
Consult :doc:`performance-qa-handbook-3.0` for benchmark methodology and
:doc:`migration-playbook-3.0` for safe upgrade procedures.

For maintainers: source truth takes precedence over prose, followed by
reproducible exact-commit tests. If an API shape, backend or capability
changes, update both the specific guide and the feature matrix at the
same time. Do not copy historical 2.0/2.1.x examples into the 3.0
reference without verifying their type signatures.
