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
     - Not yet implemented
     - Outgoing TLS with ALPN
     - HPACK and multiplex OPEN
   * - HTTP/3 outbound
     - No complete client
     - Verified native QUIC stream primitives
     - Outgoing QUIC
     - H3 control/QPACK OPEN
   * - WebSocket server
     - Inbound H1 upgrade
     - H1 upgrade/close verified
     - Accepted H1 connection
     - Masking and frame limits
   * - WebSocket client
     - No built-in client
     - Experimental verified ws/wss HTTP/1.1 dial/upgrade and masked session
     - Outbound TCP/TLS, Select loop
     - Real WSS/close, cancellation and multi-peer QA; unreleased
   * - H2/H3 extended CONNECT
     - Not advertised
     - Not qualified
     - HTTP/2 or HTTP/3
     - No support claim
   * - WebTransport
     - No client/server
     - Planned only
     - H3 sessions/streams
     - Conformance OPEN
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
     - Validated policy and pure decision engine; no worker scaling
     - Native worker groups only
     - Policy and soak tests OPEN
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

Release status interpretation
-----------------------------

The stable column describes currently shipped 2.1.2 features. The candidate
column describes implementation or planned status **on the draft Runwire 3.0
branch**, not final certification. Explicitly unsupported native QUIC datagrams,
connection migration and 0-RTT dispatch fail closed; they are not emulated.

PHP-FPM has request-owned execution, FrankenPHP/RoadRunner workers can span
requests, and Swoole/OpenSwoole own their reactors. No host offers universal
background file I/O by merely selecting a driver. Process-backed DNS and
filesystem I/O require access to a usable PHP CLI and process pipes, while
native prefork supervision requires PCNTL and POSIX.

The host compatibility matrix, coroutine ownership guide and async-file
development guide provide the host-specific lifecycle, quotas and errors.
The source-linked complete PHP API reference, frozen migration guide, H2/H3
client integration, WebTransport interoperability and release benchmarks
are separate incomplete 3.0 acceptance tasks.
