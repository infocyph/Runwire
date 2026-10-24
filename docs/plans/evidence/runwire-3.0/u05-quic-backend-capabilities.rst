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

Isolated handshake probe slice
==============================

The initial runtime implementation uses the already bounded ProcessExecutor
and a whitelist-only worker operation named quic_probe. Every native
Connection is created, TLS-verified, and closed entirely inside an off-loop
PHP worker; serialized handles NEVER cross the process boundary. The owning
Runwire reactor uses only nonblocking IPC and its normal deadline/cancellation
aborts a stalled worker. Missing ext-quic fails explicitly and insecure
verify-peer options are rejected in the worker. This proves safe isolated
handshake construction only, not stream multiplexing or interoperable QUIC
sessions. The next stage must preserve connection ownership across commands
in a long-lived worker, bound per-stream queues and errors, and pass
independent server acceptance before U05.04/U05.05 can be checked.

Independent aioquic TLS handshake acceptance
==========================================

Under the existing PHP 8.5 H3 + QUIC CI job, the php-quic extension is
installed and an independent aioquic Python server is started on an ephemeral
local UDP port, with a fresh SAN=localhost certificate. Runwire's PHP fixture
submits a native QUIC client handshake probe through its bounded external
process executor. The native Connection remains in the worker; host loop
only waits on nonblocking IPC. Verify chain/hostname using the generated
trusted certificate, require one positive response, and release all worker
and loop resources. The job fails if handshake or cleanup fails; do not treat
a successful probe as completed reusable connection/stream API. The test is
not skipped when ext-quic is available on that CI job.

Unidirectional receiver proof
=============================

An outbound client-initiated unidirectional QUIC stream cannot be read by
the sender. Worker-local metadata rejects such reads. The independent Python
server accumulates each uni stream up to 64 KiB, writes the complete payload
only after FIN, and a separate PHP echo test waits for exactly matching bytes
using bounded Runwire timers. A worker-local write count is not evidence that
a peer actually received the payload. Keep the session alive until this
receipt is observed; close worker and listener on all success/failure paths.

This is transport-level evidence and does not imply QUIC/H3 release readiness,
per-stream congestion fairness, migration, unreliable datagrams, or 0-RTT.

Real native persistence evidence checkpoint
==========================================

GitHub Security & Standards run 40059788005 completed both the original
verified off-loop TLS handshake and the independent aioquic persistent-QUIC
echo step: two bidirectional streams, sequentially opened on one dedicated
native Connection retained inside the same external ProcessExecutor worker.
The PHPForge QA matrix still found complexity, array-key typing and style
issues. Source acceptance therefore remains pending the combined QA fix plus
independent unidirectional sender-to-receiver proof and exact-head checks.

Command ordering and replay safety (Q2-G)
=======================================

A persistent native QUIC worker validates request-correlation IDs carried inside
the bounded command frame. An epoch accepts strictly increasing positive IDs,
so a canceled queued command may leave a gap, but an older or duplicate ID
cannot reissue a write, reset or FIN. The worker fails closed and closes its
native session upon detecting replay. The counter resets only after a newly
verified native handshake; host-side cancellation must never silently replay
an old stream operation. Fake-native tests cover both replay rejection and
valid increasing IDs with gaps. This is not packet-level anti-replay or 0-RTT
acceptance; real independent-server adversity remains open under U05.09.

The Q2-G source also makes the validated ALPN list explicit before native
comparison and aligns the negative-TLS test with the secure failure message.
Security & Standards #40060404846 failed the PHPForge Pest/Pint and PHPStan
gates before this change; a new passing source-linked run is required before
accepting U05.04/U05.05. The independent H3/QUIC success from that older run
cannot be substituted for a green exact-source matrix.

Q2-H bounded stream-slot reuse and independent multiplex proof
=============================================================

The one-worker QUIC backend has a configured maximum number of concurrently
retained stream objects, not an artificial lifetime ceiling on the number of
streams that may be created. Reset and native EOF free retained handles; logical
IDs increase without recycling within a verified worker epoch. Unit tests
validate stream-slot reuse after FIN/EOF and resetting one active stream while
a separate stream remains usable. Native client interop initiates two distinct
bidirectional streams before sending either request, then independently proves
both byte-accurate echoes, followed by receipt of a client-initiated uni stream
at the Python server only after its FIN. Actual async stream overlap and
independent receipt still do not satisfy congestion fairness, packet loss,
migration or U05.10 latency/throughput certification.

Security & Standards run 40062249460 passed the functional and static analyzer
lanes but failed PHPForge Pint on the worker class. The Q2-H CI debugging step
uses the same installed PHPForge Pint on the existing PHP 8.5 QUIC job to
display the real formatter patch, then reverts the temporary CI-only changes.
This is diagnostic evidence, not a style gate bypass. Remove the step after
fixing the worker and verifying all four PHPForge QA lanes green.
