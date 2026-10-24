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

Native client hard-stop and policy QA (2026-10-24)
=============================================

The extension's client constructor performs TLS handshake synchronously; its
subsequent setBlocking(false) cannot undo time already spent on the loop
thread. Runwire rejects directly using this backend for asynchronous outbound
connect. No higher-level Fiber can make this synchronous constructor safe.

The QuicClientOptions contract now explicitly reports that the native backend
provides TLS 1.3 and bidirectional/unidirectional streams, but lacks
asynchronous handshake, RFC 9221 datagrams, migration and 0-RTT.
Optional methods fail closed on changed address+port, datagram requirements,
and attempted early application dispatch. These checks must be enforced
inside an operational worker/session backend once built; options-only tests
do not constitute independent end-to-end native QUIC acceptance.

U05.04 path: a persistent off-loop worker *owns* the native QUIC connection
and all its streams and runs a bounded request/response IPC protocol. Session
and stream objects are not serializable across PHP process boundaries.
The worker must implement explicit close/reset, per-stream quotas, response
flow control, cancellation/deadline correlation, crash recovery, and event
loop fairness. An alternate native QUIC extension with a documented truly
async constructor may replace the worker only after real-loop verification.
