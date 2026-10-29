Runtime ownership and application lifecycle
===========================================

Why ownership matters
---------------------

A loop, listening socket, accepted connection, pending dial, TLS session,
HTTP stream, callback, coroutine task or subprocess executor must have an
identifiable owner. Without that, a late callback may operate on an already
closed handle, inherited fork children may cancel watchers belonging to
their parent, or graceful shutdown may abandon admitted responses.

Runwire separates four kinds of lifetime:

* **Runtime:** selected driver, listener binding, application lifetime.
* **Supervisor:** master process, worker groups, PID/generation bookkeeping.
* **Worker:** worker context, loop, owned connections and background jobs.
* **Request or task:** cancellation, deadline, response writer and child work.

The scope that creates a resource is normally responsible for cancelling
outstanding operations, consuming completion exactly once and releasing the
resource. An explicit handoff transfers this responsibility; handing out
a PHP stream handle without transferring its callback/watch ownership is
not a valid handoff.

Runtime entry points
--------------------

The public entry point is `Infocyph\Runwire\Runtime`. Its documented
method contracts include `create(?RuntimeOptions)`,
`listen(Server|StreamServer|DatagramServer)`, `run()`,
`stop(bool)`, `selection()`, `onEvent(callable)`,
`recycle(string, int)` and `reload()`.

.. code-block:: php

   <?php
   use Infocyph\Runwire\Runtime;
   use Infocyph\Runwire\Server;

   // Application-specific HTTP handler goes here.
   $server = Server::http('127.0.0.1:8080', $handler);
   Runtime::create()->listen($server)->run();

The handler above is intentionally supplied by the application. For a
self-contained HTTP fixture and its response-writer contract, use
`tests/fixtures/portable_native_acceptance.php` and
:doc:`inbound-handbook-3.0`. Request callbacks are not places to create
a new permanent reactor per request.

State transitions
-----------------

.. code-block:: text

   Runtime configured
      -> listener bound
      -> worker started
      -> application started
      -> ready/admitting requests
      -> drain requested / stop accepting
      -> outstanding admitted work completes
      -> coroutine/executor/socket cleanup
      -> worker exit -> parent reaps PID

   Unexpected child failure
      -> exit observed -> restart budget/backoff
      -> new generation ready -> resume admission

   Rolling reload
      -> launch replacement -> verify readiness
      -> retire old generation -> drain or forced stop at deadline

The parent must not declare a replacement ready merely because its PID
exists. Readiness, ownership and process reaping are independently
tracked; one worker slot must not have two *current* owners.

Graceful versus forced shutdown
-------------------------------

Graceful termination stops new admission before draining accepted work.
Native H2 sends GOAWAY and completes previously admitted streams subject
to protocol limits and a drain deadline. QUIC/H3 likewise has its own
stream and connection draining semantics, which cannot be represented by
simply closing the TCP listener.

Forced termination is different: SIGKILL, a cancelled deadline or an
operator force-stop can lose in-flight work. Configure supervisors and
load balancers for graceful handoff when correctness depends on
completion. Do not promise zero loss after a hard kill.

Stop processing needs a wakeup path even if one cleanup callback throws.
The worker's cleanup inventory includes pending DNS/dial racing, active
connections, pools, QUIC sessions, background scopes, periodic timers,
process executors, signal/control watchers and IPC pipes. Independent
resources are retired even when one observer fails. A timer or watcher
that fails to cancel must remain available for a subsequent cleanup
attempt rather than being silently marked gone.

Fork and process ownership
--------------------------

A TCP listener bound *before* fork may be attached to an event loop in a
child worker, subject to the listener's intended prefork policy. Once
the listener or dialer has become active and PID-owned, a forked child
must not mutate the inherited parent's event loop, pending dial, pool,
session or connection state. Use fresh child-owned resources or a
deliberately supported prefork handoff.

Native checks exercise:
   
.. code-block:: console

   php .ci/c3_listener_fork_smoke.php
   php .ci/c3_dialer_smoke.php
   php .ci/c3_cleanup_fault_smoke.php
   php .ci/c3_supervisor_fault_smoke.php

These scripts require the capabilities stated by the test, including
POSIX/PCNTL for actual fork and signal scenarios. They are not intended
to provide production daemon startup scripts.

Application startup, request reset and shutdown
-----------------------------------------------

A long-lived worker needs application startup and shutdown hooks separate
from per-request cleanup. Persistent state may be shared intentionally,
but tenant/request identifiers, cancellation tokens, authentication
headers and response writers should not leak across requests. Host-owned
drivers may have one PHP process serving many requests; a request exit
does not imply worker exit.

Use the `RuntimeApplicationInterface` or application-factory contract for
lifecycle-aware integrations, and consult :doc:`host-compatibility-3.0`
for host-specific request ownership. Periodic and background work belongs
to the worker, not to a response writer that has already closed.

Failure semantics checklist
---------------------------

* Record the state reached at failure: unbound, connecting, TLS verifying,
  admitted, streaming, draining, completed or closed.
* Cancel timers and watchers that are no longer needed.
* Complete each public callback once. Do not emit a second completion
  from the cleanup path.
* Prevent inherited PID state from being used after fork.
* On shutdown, stop admission before draining and enforce a bounded
  graceful deadline.
* Keep the original error when cleanup also fails, and report any
  secondary failures separately.
* Do not retry a partially received non-idempotent response as if it
  had never been admitted.

Further reading: :doc:`concurrency-handbook-3.0`,
:doc:`supervision-handbook-3.0` and :doc:`troubleshooting-handbook-3.0`.
