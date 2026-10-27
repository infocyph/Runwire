Complete feature status catalog — Runwire 2.1.2 and 3.0
============================================================

**Published stable:** Runwire 2.1.2. **Development candidate:** 3.0.0.
An implemented source primitive is not a public release guarantee.

Network, protocols and worker capabilities
------------------------------------------

.. list-table:: Versioned feature inventory with host and extension constraints
   :header-rows: 1
   :widths: 25 18 22 19 16

   * - Capability
     - Published 2.1.2
     - 3.0 source status
     - Host and transport
     - Prerequisites and limits
   * - TCP and Unix server
     - Inbound listener
     - Shared connection owner
     - Native Select and supported hosts
     - 64-bit PHP 8.4+
   * - TLS server
     - TLS listener
     - Verified ownership
     - Native and host-managed TCP
     - OpenSSL trust policy
   * - HTTP/1.1 server
     - Streaming H1
     - Existing parser and bounded writer
     - Accepted TCP/TLS
     - Shared byte budgets
   * - HTTP/2 server
     - Multiplexed H2
     - Single stream scheduler and flow owner
     - Accepted TCP/TLS
     - HPACK / GOAWAY / frame caps
   * - HTTP/3 server
     - Optional H3
     - Real independent H3/QUIC peer tests
     - Native QUIC host only
     - ext-quic / compatible OpenSSL
   * - HTTP/1.1 outbound
     - No complete client
     - Verified H1 exchange, bounded upload and opt-in safe redirect/retry
     - Outgoing TCP/TLS
     - No general-purpose H2/H3 fallback
   * - HTTP/2 outbound
     - No complete client
     - Verified H2 request/response, bounded multiplex, flow control and peer GOAWAY; U07 accepted, U17 final certification pending
     - Outgoing TLS with ALPN
     - Independent TLS/ALPN hyper-h2 peer QA passed; no H2 extended CONNECT
   * - HTTP/3 outbound
     - No complete client
     - Verified H3 client control streams, bounded QPACK, sibling requests and cancellation; U07 accepted
     - Outgoing QUIC
     - Native ext-quic and independent aioquic/ngtcp2 QA; final U17 certification pending
   * - WebSocket server
     - Inbound H1 upgrade
     - H1 upgrade/close verified
     - Accepted H1 connection
     - Masking and frame limits
   * - WebSocket client
     - No built-in client
     - Verified outbound ws/wss HTTP/1.1 handshake, subprotocols and masked framing; U09 accepted
     - Outbound TCP/TLS, Select loop
     - Independent WSS/close/cancellation/multi-peer QA passed; no H2/H3 extended CONNECT
   * - H2/H3 extended CONNECT
     - Not advertised
     - Not qualified
     - HTTP/2 or HTTP/3
     - No support claim
   * - Inbound and outbound UDP
     - Datagram server
     - Connected/unconnected endpoint budgets
     - Native UDP
     - OS socket support
   * - Native QUIC outbound
     - No unified client
     - Verified TLS 1.3/ALPN peer connection
     - Optional native worker
     - No native migration/0-RTT/datagrams
   * - TCP/TLS/Unix dialer
     - Not unified
     - Nonblocking verified outgoing streams
     - Portable and host-loop adapters
     - OpenSSL for TLS
   * - Async DNS/Happy Eyeballs
     - Not complete
     - Off-loop DNS and racing
     - Bounded process executor
     - proc_open/process pipes
   * - Pooled connections
     - Not unified
     - Scoped bounded active leases, FIFO waiters, idle TTL and owner transfer
     - Outbound streams
     - No integrated high-level HTTP dial pool
   * - CONNECT/SOCKS5 proxy
     - Not unified
     - Proxy tunnel development
     - Outgoing TCP/TLS
     - Credential policy gated
   * - Bidirectional relay
     - Not unified
     - Bounded duplex relay with drained cleartext half-close and cancellation
     - TCP/Unix streams
     - TLS close-notify and release throughput remain OPEN
   * - Fiber tasks and cancellation
     - Supported
     - Structured scope/channel/deadline ownership
     - Select and host reactors
     - Exactly one resource owner
   * - Async disk jobs
     - Not built in
     - Bounded subprocess-backed read/write/stat with opt-in root policy
     - Scope or worker-owned executor
     - No native disk AIO or race-free sandbox
   * - File/network transfer
     - Not built in
     - Experimental bounded chunk bridge
     - TCP/Unix plus file executor
     - Slow-peer/full QA pending
   * - Atomic file durability
     - Not guaranteed
     - Crash-safe replace not yet implemented
     - Host filesystem semantics
     - fsync/sandbox policy OPEN
   * - Native process supervision
     - Restart and reload
     - Generation and recycle controls
     - Native fork/signal owner
     - PCNTL and POSIX required
   * - Automatic autoscaling
     - Not built in
     - Real hysteresis-controlled native worker scale-out/scale-in; C3 QA accepted
     - Native PCNTL/POSIX worker groups only
     - Full U15 release-performance soak remains OPEN
   * - FPM/FrankenPHP/RoadRunner
     - Host adapters
     - Real-host request isolation tested
     - External host owns workers
     - Explicit socket lifetimes
   * - Swoole/OpenSwoole
     - Host adapter
     - Real reactor and outgoing dial tested
     - External host owns reactor
     - No competing loop
   * - Optional ext-event
     - Optional acceleration
     - Descriptor/budget tests on PHP 8.4
     - EventLoop adapter
     - Full cross-PHP parity OPEN
   * - Security and diagnostics
     - Existing controls
     - Bounded budgets and observations
     - Host/worker/connection scopes
     - Final fuzz/security QA OPEN

Release status interpretation and limitations
---------------------------------------------

The stable column describes currently shipped 2.1.2 features. The candidate
column describes implementation or planned status **on the draft Runwire 3.0
branch**, not final certification. Explicitly unsupported native QUIC datagrams,
connection migration and 0-RTT dispatch fail closed; they are not emulated.

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
development guide provide the host-specific lifecycle, quotas and errors.
The source-linked complete PHP API reference, 2.1.2-to-3.0 migration
ledger and bounded H2/H3 client integration have task-scoped QA acceptance.
The full mixed 1800-second soak, externally hosted Read the Docs preview
and final frozen-source release certification remain incomplete.
