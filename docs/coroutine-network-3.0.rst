=======================================
Coroutine network ownership (3.0)
=======================================

This chapter explains live socket ownership across callbacks,
Fibers, cancellation scopes and explicit connection handoffs.

Ownership model
===============

An established stream has exactly one active cancellation owner.
Callback-driven and Fiber-driven I/O use the same
``Infocyph\\Runwire\\Network\\Connection`` primitive. They must not
run competing readers or replace another consumer's callback slots.

- ``Connection::bindOwner()`` installs the initial cancellation token.
- ``Connection::transferOwner()`` rebinds a live connection to a new
  owner, first rejecting an already-cancelled replacement token.
- ``AsyncConnection::transferTo()`` explicitly hands a quiescent,
  scope-owned connection to a worker or pool token. The coroutine
  adapter is disposed after the transfer; the returned core connection
  is the only valid handle for further callback operations.
- ``AsyncConnection::adopt()`` transfers a previously pool-owned core
  connection into a new coroutine scope. If adapter installation fails,
  adoption aborts the connection instead of leaving an unobserved owner.

A normal scope exit aborts any connection still owned by that scope.
A transferred connection is not closed merely because the previous
request finishes. The new owner must eventually close or transfer it.

Calling ``AsyncConnection::dispose()`` removes the Fiber-facing adapter's
callbacks, **not** the established socket's scope ownership. The socket
continues to belong to its request and closes on scope exit. Use
``transferTo()`` before disposal when transferring it into a pool.

Dialing and cancellation
========================

The Fiber-facing ``AsyncDialer`` delegates to the common hostname
racer, DNS process executor and TCP/TLS connector. A call-specific token
does not replace the containing scope's token: both are observed, and
the earlier cancellation or deadline takes effect. A successful socket
that arrives during cancellation is aborted instead of handed off.

.. code-block:: php

   use Infocyph\Runwire\Coroutine\CoroutineScope;
   use Infocyph\Runwire\Coroutine\Network\AsyncDialer;
   use Infocyph\Runwire\Network\DialOptions;

   // Register this callback with a request-owned coroutine handler
   // or an externally driven host loop.
   $handleRequest = static function (CoroutineScope $scope) use ($racer): void {
       $connection = (new AsyncDialer($racer, $scope))->connect(
           'tcp://example.test:443',
           new DialOptions(connectTimeoutSeconds: 5.0),
       );

       // Read/write operations use the same established core connection.
       // The connection is closed when this scope exits unless transferred.
   };

A hostname is resolved off-loop, not through a synchronous DNS query
in the event-loop thread. An unbounded or blocked worker queue is never
a valid reason to ignore the caller's deadline.

Pooling and scope handoff
=========================

.. code-block:: php

   use Infocyph\Runwire\Coroutine\CoroutineScope;
   use Infocyph\Runwire\Coroutine\Network\AsyncConnection;

   // $pooledConnection was previously bound to $poolSource->token().
   // This callback belongs to a request host whose loop remains active.
   $handlePooledRequest = static function (CoroutineScope $scope) use (
       &$pooledConnection,
       $poolSource
   ): void {
       $adapter = AsyncConnection::adopt($pooledConnection, $scope);

       // Only hand a quiescent adapter back to the pool.
       $pooledConnection = $adapter->transferTo($poolSource->token());
   };

Do not transfer while a coroutine is awaiting receive, drain or close,
or while an EOF fragment remains buffered. Reuse must not retain the
previous request's cancellation observer or callback registry entries.
A different active owner token is required for every explicit handoff.

Admission and deadline budgets
==============================

The event loop does not create unbounded work when an executor or
connection queue is full. ``CoroutinePolicy`` independently limits task
count, runnable backlog, future waiters, and waiters per primitive.
``ProcessExecutor`` has bounded worker and queue capacity for
DNS/filesystem jobs. ``HappyEyeballsDialer`` applies a separate maximum
to outstanding logical hostname races. At saturation, new work fails
with an overload error without displacing previously admitted work.

Child scope deadlines also apply while waiting for a socket read.
Caller DNS/dial options and off-loop filesystem work must honor the
earliest inherited/requested deadline; they must not restart the clock
at each stage of a multi-step request.

TLS close safety
================

On strict TLS streams, an unauthenticated peer EOF is classified as
``TLS_UNVERIFIED_EOF``. The coroutine adapter rejects a pending or
subsequent receive in this case; it must not silently return the empty
string as if a ``close_notify`` had been verified.

Raw TCP write-half-close is not a substitute for TLS shutdown. Code
that requires authenticated TLS EOF must handle the explicit error or
use a protocol-defined framing/close contract.

Status and verification
=======================

The current tests cover real socket pairs, repeated request/pool
handoffs, cancellation races and deadline-triggered cleanup. Longer
FD/RSS and latency certification is a separate release gate; green
unit tests alone do not certify sustained performance.

See :doc:`features` for published-versus-target feature status and
:doc:`architecture` for the overall unified-runtime design.

Event loop and scope lifecycle
==============================

The selected loop is owned by the execution context, not by a request.
Standalone native mode can own its ``SelectLoop`` or optional
``EventLoop``; Swoole/OpenSwoole hosts already own the reactor and
``SwooleLoop`` only adapts readiness, timers and callbacks.

.. code-block:: text

   Host-owned reactor or Runwire standalone loop
         |
         v
   CoroutineRuntime / request CancellationSource
         |
         +--> CoroutineScope --> child Task / Future
         |           |
         |           +--> AsyncDialer --> DNS worker --> Dialer
         |                                   |
         |                                   v
         |                              Connection
         |                                   |
         |                           AsyncConnection
         |                                   |
         +--> finish, cancel or deadline ---+
                         |
                  explicit transferTo(new owner)?
                   /                       \
                 no                       yes
                 |                         |
              close owned              remove old callbacks
              connection               and old cancellation
              + watchers               owner; pool now owns it

Exactly one active cancellation owner is allowed per established socket.
The earliest inherited deadline wins. If cancellation races with
successful dialing, the resulting connection is aborted rather than
orphaned or transferred after scope shutdown. Transferring a quiescent
adapter removes the old scope's callbacks and observer before the new
pool/worker token takes ownership; disposing an adapter without transfer
never changes the socket's request owner.

A host-managed event loop survives an individual request. Standalone
loop shutdown waits for owned watchers/timers to settle. This lifecycle
diagram is development documentation, not a performance certification.
