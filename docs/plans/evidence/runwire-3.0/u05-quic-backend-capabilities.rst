Outbound QUIC backend capabilities
==================================

Runwire separates QUIC client security policy from actual transport creation.
The native php-quic backend can implement TLS 1.3 stream-based clients and
verified peer identity. It currently lacks RFC 9221 datagrams, migration and
application 0-RTT. All three are explicitly rejected by QuicClientOptions
when requested. Unsupported features must never silently downgrade.

Critically, ext-quic's documented client Connection constructor completes a
blocking handshake before setBlocking(false) can be called. It therefore
must NOT be invoked directly on Runwire's event-loop thread. The future U05.04
client transport must use a genuine off-loop execution strategy or a QUIC
backend whose connection establishment is natively asynchronous. A Fiber
around the blocking constructor would not make it asynchronous. This policy
class is preparatory: U05.04-U05.10 remain open until operational real-server
acceptance and benchmark evidence exists.
