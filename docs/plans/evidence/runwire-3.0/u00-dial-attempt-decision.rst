.. _runwire-u00-architecture-decision:

============================================================
U00: pending dial lifecycle and established socket ownership
============================================================

**Status:** Runwire 3.0 architecture decision and executable contract, not
a fully implemented outbound dialer. The new state guard lives in
``Network\\Internal\\DialAttemptLifecycle`` and is enforced by
``tests/DialAttemptLifecycleTest.php``.

Ownership and construction
==========================

One pending attempt owns its DNS work, candidate sockets, watchers,
stage deadlines and cancellation until exactly one verified transport
is transferred. Only the future shared ``ConnectionFactory`` adopts a
verified socket and creates an established ``Connection``. The dialer
must **never** reimplement the established stream's read/write queues,
partial-write logic, watermarks, EOF handling or callback dispatch.

After a successful handoff the attempt has no remaining live socket or
event watchers. An abandoned Happy Eyeballs attempt must release all
candidates. A cancelled or failed attempt may not be revived. The
factory's output has one resource scope and one final cleanup path.
Request-scoped connections do not survive host request shutdown without
an explicit worker-scope ownership transfer.

Pending dial phases
===================

.. code-block:: text

   NEW -> RESOLVING -> CONNECTING -> HANDSHAKING -> READY -> HANDED_OFF
    |                       |                  |          |
    +-> CONNECTING ---------+                  |          |
    +-> FAILED/CANCELLED <- terminal failure/cancellation from every
       nonterminal phase

The direct CONNECTING -> READY path is permitted for plain TCP/Unix.
TLS/QUIC require successful handshake and peer-verification before READY.
HANDOFF is permitted only once and only from READY. All terminal states
have no outgoing transitions. The executable matrix tests **64 phase
pairs**, both accepted and forbidden.

Established connection lifecycle
================================

The established stream separately tracks read EOF, write FIN, bounded
queued bytes and a terminal CLOSE state. A peer read FIN must not erase
accepted outbound bytes. A write FIN rejects new writes. Duplicate
close, cancellation and timeout races must release the resource once.

ConnectionFactory responsibilities
==================================

* Adopt only a connected, verified socket; reject pending or revoked
  socket handles.
* Attach exactly one loop watcher owner, scope, buffer budget and
  close callback, and return the same established I/O abstraction for
  inbound and outbound connections.
* Do not share an OS socket between parent and forked worker by accident.
* Route TCP/Unix stream read/write through one engine; keep QUIC
  transport-stream/datagram and UDP packet semantics separate.
* Do not change ``LoopInterface`` for socket dialing if its existing
  read/write readiness and timers suffice.
* Allow intentional breaking changes to ``Connection`` constructor
  and ``AsyncConnection`` ownership in the U02 implementation, with
  signed source migration examples.

**Contract QA:** the transition guard is an executable baseline for U00.
U02/U03 must separately prove actual socket adoption, no competing I/O
engine and exactly-once owner release under real network races.
