WebTransport ownership design — not implemented
===============================================

There is no current WebTransport client or server in Runwire 3.0.
These are architecture requirements only.

Authority and admission
-----------------------

An incoming HTTP/3 WebTransport extended CONNECT must verify QUIC TLS
1.3 identity, ALPN h3, origin/authority and explicit permissions.
Admission needs bounded sessions, concurrent streams, queued bytes,
idle timers and negotiated datagram MTU before allocating resources.

Session and stream lifecycle
----------------------------

Each session owns an immutable ID, authority, scope cancellation token,
deadline and its stream registry. A stream belongs to one session and
cannot transfer between tenant requests. FIN only closes one direction.
RESET_STREAM and STOP_SENDING release the affected stream without
terminating sibling sessions. Closing a session rejects new admissions,
drains existing streams within deadline and releases all watchers.

Datagrams and native QUIC limitations
-------------------------------------

Datagrams are unordered and unreliable, require explicit association
with a live session, and must be bounded by negotiated maximum size.
The native ext-quic backend currently does not supply datagrams,
connection migration or application early 0-RTT; no reliable-stream
emulation may silently claim WebTransport support.

Independent conformance requirements
------------------------------------

End-to-end support requires independent real peers for both directions,
origin/authority rejection, session injection, uni/bidi stream resets,
datagram loss/reordering/MTU, slow peer, overload, H3 GOAWAY,
cancellation, idle-timeout, native worker recycle and FD/watcher cleanup.
Record successful RPS, p99, CPU and RSS. Existing H3 client tests
alone do not certify WebTransport interoperability.

This is the architectural U09.05 scope. Actual endpoints and
datagram support are separate U09.06 through U09.10 tasks.
