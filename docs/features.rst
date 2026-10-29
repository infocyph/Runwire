Features and supported capabilities
===================================

Capabilities depend on the runtime driver, host permissions and installed
PHP extensions. Experimental integrations and unsupported transport modes
are explicitly identified.


Network, protocols and worker capabilities
------------------------------------------

.. list-table:: Feature inventory with host and extension constraints
   :header-rows: 1
   :widths: 25 36 19 20

   * - Capability
     - 3.0 source status
     - Host and transport
     - Prerequisites and limits
   * - TCP and Unix server
     - Shared connection owner
     - Native Select and supported hosts
     - 64-bit PHP 8.4+
   * - TLS server
     - Verified ownership
     - Native and host-managed TCP
     - OpenSSL trust policy
   * - HTTP/1.1 server
     - Existing parser and bounded writer
     - Accepted TCP/TLS
     - Shared byte budgets
   * - HTTP/2 server
     - Single stream scheduler and flow owner
     - Accepted TCP/TLS
     - HPACK / GOAWAY / frame caps
   * - HTTP/3 server
     - Real independent H3/QUIC peer tests
     - Native QUIC host only
     - ext-quic / compatible OpenSSL
   * - HTTP/1.1 outbound
     - Verified H1 exchange, bounded upload and opt-in safe redirect/retry
     - Outgoing TCP/TLS
     - No general-purpose H2/H3 fallback
   * - HTTP/2 outbound
     - Verified H2 request/response, bounded multiplex, flow control and peer GOAWAY; independent peer testing
     - Outgoing TLS with ALPN
     - Independent TLS/ALPN hyper-h2 peer QA passed; no H2 extended CONNECT
   * - HTTP/3 outbound
     - Verified H3 client control streams, bounded QPACK, sibling requests and cancellation; validated with focused tests
     - Outgoing QUIC
     - Native ext-quic and independent aioquic/ngtcp2 QA; scoped independent protocol QA
   * - WebSocket server
     - H1 upgrade/close verified
     - Accepted H1 connection
     - Masking and frame limits
   * - WebSocket client
     - Verified outbound ws/wss HTTP/1.1 handshake, subprotocols and masked framing; validated with focused tests
     - Outbound TCP/TLS, Select loop
     - Independent WSS/close/cancellation/multi-peer QA passed; no H2/H3 extended CONNECT
   * - H2/H3 extended CONNECT
     - Not qualified
     - HTTP/2 or HTTP/3
     - No support claim
   * - Inbound and outbound UDP
     - Connected/unconnected endpoint budgets
     - Native UDP
     - OS socket support
   * - Native QUIC outbound
     - Verified TLS 1.3/ALPN peer connection
     - Optional native worker
     - No native migration/0-RTT/datagrams
   * - TCP/TLS/Unix dialer
     - Nonblocking verified outgoing streams
     - Portable and host-loop adapters
     - OpenSSL for TLS
   * - Async DNS/Happy Eyeballs
     - Off-loop DNS and racing
     - Bounded process executor
     - proc_open/process pipes
   * - Pooled connections
     - Scoped bounded active leases, FIFO waiters, idle TTL and owner transfer
     - Outbound streams
     - No integrated high-level HTTP dial pool
   * - CONNECT/SOCKS5 proxy
     - Proxy tunnel development
     - Outgoing TCP/TLS
     - Credential policy gated
   * - Bidirectional relay
     - Bounded duplex relay with drained cleartext half-close and cancellation
     - TCP/Unix streams
     - TLS close-notify/relay throughput are not release-qualified features; no TLS relay guarantee
   * - Fiber tasks and cancellation
     - Structured scope/channel/deadline ownership
     - Select and host reactors
     - Exactly one resource owner
   * - Async disk jobs
     - Bounded subprocess-backed read/write/stat with opt-in root policy
     - Scope or worker-owned executor
     - No native disk AIO or race-free sandbox
   * - File/network transfer
     - Experimental bounded chunk bridge
     - TCP/Unix plus file executor
     - Slow-peer transfer path remains experimental, not a release-qualified general file sender
   * - Atomic file durability
     - Crash-safe replace not yet implemented
     - Host filesystem semantics
     - No crash-durable or race-free sandbox guarantee
   * - Native process supervision
     - Generation and recycle controls
     - Native fork/signal owner
     - PCNTL and POSIX required
   * - Automatic autoscaling
     - Real hysteresis-controlled native worker scale-out/scale-in; available in native mode
     - Native PCNTL/POSIX worker groups only
     - Full 1800s mixed worker/process-tree soak qualified on PHP 8.4 and 8.5
   * - FPM/FrankenPHP/RoadRunner
     - Real-host request isolation tested
     - External host owns workers
     - Explicit socket lifetimes
   * - Swoole/OpenSwoole
     - Real reactor and outgoing dial tested
     - External host owns reactor
     - No competing loop
   * - Optional ext-event
     - Portable/native PHP8.4 and PHP8.5 CI acceptance
     - EventLoop adapter
     - Real independent PHP8.4/8.5 compatibility verified
   * - Security and diagnostics
     - Bounded budgets and observations
     - Host/worker/connection scopes
     - Source-frozen PHPForge stable/lowest QA and independent native protocol/security lanes accepted

Capabilities and limitations
----------------------------

The feature column describes supported capabilities and their constraints.
Unsupported native QUIC datagrams, connection migration and 0-RTT dispatch
fail closed; they are not emulated.

**WebTransport is not included in Runwire 3.0.** At release planning time,
the available PHP native QUIC backend (``ext-quic`` with OpenSSL) did not
expose the RFC 9221 QUIC DATAGRAM send/receive capability needed for full
WebTransport over HTTP/3. Therefore Runwire does not ship or advertise a
WebTransport client, server, session API or datagram fallback in 3.0.
This is a limitation of the selected PHP native backend at this release,
not a limitation of QUIC itself. Ordinary HTTP/3, QUIC streams, UDP
datagrams and WebSocket remain in scope and are not WebTransport substitutes.
No promise of support in a future release is implied.

**Nested TLS over an HTTPS proxy is not yet supported.** A cleartext
HTTP CONNECT tunnel can be upgraded to TLS independently verified for the
original destination, including certificate name, SNI and ALPN. A
separately TLS-encrypted HTTPS proxy cannot currently be used as a
second independently verified origin-TLS transport through the PHP
stream backend. Attempted TLS-over-TLS upgrading closes the proxy socket
and refuses the operation; it never uses the proxy's certificate as
the origin's identity. See :doc:`connection-policies-3.0`.
This does not affect ordinary verified TLS dials.


PHP-FPM has request-owned execution, FrankenPHP/RoadRunner workers can span
requests, and Swoole/OpenSwoole own their reactors. No host offers universal
background file I/O by merely selecting a driver. Process-backed DNS and
filesystem I/O require access to a usable PHP CLI and process pipes, while
native prefork supervision requires PCNTL and POSIX.

The host compatibility matrix, coroutine ownership guide and async-file
I/O guide explain per-host lifecycles, quotas and errors. The migration
guide covers differences from previous versions. The individual
feature guides explain public usage and link to focused tests. For independent
protocol and 1800-second mixed resource testing, consult the
:doc:`performance-qa-handbook-3.0`.


Where each major feature has examples and full explanations
-----------------------------------------------------------

The capability table above describes availability; the following
references explain what to implement, why to select it, and how to
validate the actual behavior. Start with
:doc:`feature-workflows-3.0` for runnable end-to-end examples.

.. list-table:: Feature-to-guide and test reference
   :header-rows: 1
   :widths: 28 40 32

   * - Feature family
     - Explanation and walkthrough
     - Focused evidence
   * - Native HTTP/1.1
     - :doc:`inbound-handbook-3.0` and :doc:`feature-workflows-3.0`
     - ``tests/Http1ConnectionTest.php``
   * - HTTP/2 multiplex and drain
     - :doc:`protocols-3.0` and :doc:`protocol-failure-atlas-3.0`
     - ``tests/Http2ConnectionTest.php``
   * - Native HTTP/3/QUIC
     - :doc:`protocols-3.0` and :doc:`transport-guide-3.0`
     - ``tests/Http3ConnectionStateTest.php``
   * - WebSocket upgrade and backpressure
     - :doc:`inbound-handbook-3.0`
     - ``tests/WebSocketIntegrationTest.php``
   * - TCP/Unix framed connections
     - :doc:`inbound-handbook-3.0` and :doc:`feature-workflows-3.0`
     - ``tests/fixtures/portable_native_acceptance.php``
   * - UDP datagrams
     - :doc:`inbound-handbook-3.0` and :doc:`transport-guide-3.0`
     - ``tests/fixtures/portable_native_acceptance.php``
   * - DNS/dial/TLS/SSRF
     - :doc:`outbound-handbook-3.0` and :doc:`security-handbook-3.0`
     - ``tests/OutboundAddressPolicyTest.php``
   * - H1 pool / H2-H3 sessions
     - :doc:`connection-policies-3.0` and :doc:`outbound-handbook-3.0`
     - ``tests/HttpOutboundDispatcherTest.php``
   * - Proxies and stream relay
     - :doc:`connection-policies-3.0`
     - ``tests/ProxyAuthenticatedTlsInteropTest.php``
   * - Coroutine and Fiber ownership
     - :doc:`concurrency-handbook-3.0` and :doc:`coroutine-network-3.0`
     - ``tests/BatchLCoroutineSchedulerTest.php``
   * - Async files and bounded streaming
     - :doc:`filesystem-handbook-3.0` and :doc:`async-file-io-3.0`
     - ``tests/AsyncFileSystemTest.php``
   * - Native supervisor/autoscale
     - :doc:`supervision-handbook-3.0`
     - ``tests/SupervisorTest.php``
   * - Hosted runtimes
     - :doc:`host-compatibility-3.0`
     - Real Hosted Runtime Acceptance workflow
   * - Metrics/W3C tracing
     - :doc:`observability-handbook-3.0`
     - ``tests/TraceContextTest.php``
   * - Security and production deployment
     - :doc:`security-handbook-3.0` and :doc:`production-operations-3.0`
     - PHPForge Security & Standards workflow

The focused tests are **examples of actual checked-in evidence**, not
permission to skip independent protocol, native-extension or
performance certification. Unsupported WebTransport, automatic H2/H3
session discovery, nested TLS through HTTPS proxies and kernel-native
AIO remain excluded or separately qualified future work.
