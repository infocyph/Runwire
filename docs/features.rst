==============================
Complete feature-status catalog
==============================

This document distinguishes published **2.1.2 features** from proposed
**3.0.0 targets**. A 3.0 target is not yet an implemented public API.

.. list-table:: Network, protocol and I/O capability inventory
   :header-rows: 1
   :widths: 48 25 27

   * - Capability
     - Published 2.1.2
     - Target 3.0
   * - HTTP/1.1, HTTP/2, HTTP/3 servers
     - Supported with required extensions
     - Unified transport
   * - HTTP/1.1, HTTP/2, HTTP/3 clients
     - No complete built-in clients
     - Streaming clients
   * - WebSocket
     - Server
     - Client and server
   * - WebTransport
     - Not built in
     - Client and server
   * - Inbound TCP, TLS, Unix, UDP
     - Supported
     - Unified ownership
   * - Outbound TCP, TLS and Unix dialer
     - No complete built-in dialer
     - Fully asynchronous
   * - Outbound UDP and QUIC
     - No unified client API
     - Full client/server
   * - Async DNS and IPv4/IPv6 racing
     - Not built in
     - Happy Eyeballs
   * - Connection pooling / HTTP CONNECT / SOCKS5
     - Not built in
     - Bounded and secure
   * - Bidirectional stream relay
     - Not unified
     - Half-close and backpressure
   * - Structured coroutines and cancellation
     - Supported
     - Unified ownership
   * - Select / optional ext-event and hosted modes
     - Supported
     - Host-aware I/O
   * - Async filesystem executor
     - Not built in
     - Genuine off-event-loop I/O
   * - Adaptive native worker autoscaling
     - Not built in
     - Bounded native scaling
   * - Security, diagnostics, performance metrics
     - Existing features
     - End-to-end visibility

Every shipped 3.0 capability must eventually have a complete RST API reference,
examples, runtime/extension support matrix, limits and security guidance. No
unimplemented feature may be presented as released.
