.. _runwire-u01-loop-backend-audit:

========================================================
U01: actual loop backend and watcher ownership comparison
========================================================

**Status:** Implementation assessment, not proof that all future U01
executor and fairness gates are complete. Source baseline is the 3.0
development branch after U00 and the published 2.1.2 API lock.

Shared public contract
======================

``LoopInterface`` exposes ``onReadable``, ``onWritable``,
``delay``, ``repeat``, ``defer``, ``cancel``,
``now``, ``run`` and ``stop``. All three backends
implement the interface; it should not grow mandatory executor methods
merely to enable asynchronous outbound dialing.

.. list-table:: Backend ownership and readiness matrix
   :header-rows: 1
   :widths: 19 24 22 35

   * - Backend
     - Socket event owner
     - Timer behavior
     - Lifecycle and capabilities
   * - ``SelectLoop``
     - Runwire ``stream_select()`` watchers
     - Nanosecond monotonic timer queue, due work bounded at 256/tick
     - Portable loop, 256 native connections; cancelled IDs removed
   * - ``EventLoop``
     - Runwire ``ext-event`` base and per-direction event
     - Native libevent timer callbacks, monotonic diagnostics
     - Optional ext-event; no global extension requirement
   * - ``SwooleLoop``
     - Host-owned reactor receives watch/read/write registrations
     - Millisecond host timers, callback IDs tracked by Runwire
     - Requires active host coroutine to run; no competing reactor

Existing regression evidence
============================

* ``tests/SelectLoopTest.php`` covers cancellation, self-cancellation,
  closed watcher cleanup, 600 due timers, I/O fairness and stop/reentry.
* ``tests/EventLoopTest.php`` covers optional ext-event, portable fallback,
  native read dispatch and large descriptor counts.
* ``tests/SwooleLoopTest.php`` and
  ``tests/Fixtures/BatchOSwooleReactor.php`` validate host-owned
  coroutine dispatch and refusal to run without an active host coroutine.
* ``tests/LoopBackendConformanceTest.php`` adds a shared nonblocking
  watcher registration/cancellation probe for all backends and real
  readable socket dispatch on supported native backends.

Behavior requiring further U01 work
==================================

* Ensure **no delayed callback after cancellation** and no observer retention
  under close/reopen and simultaneous read/write watcher teardown (U01.02).
* Preserve fairness with deferred/timer/socket bursts across all backends
  where scheduler ownership allows comparable tests (U01.03).
* Define consistent monotonic deadline semantics for 0/negative/huge
  timeouts while respecting millisecond granularity in Swoole (U01.04).
* Add a bounded off-event-loop worker/executor backend for DNS/file I/O;
  existing watcher loops are not a truthful replacement for it
  (U01.05-U01.07).

**Important:** A host-owned Swoole reactor must not be created or run twice
from Runwire, and portable SelectLoop is intentionally bounded. The test
fixture exercises watcher ownership without claiming actual Swoole
integration has passed on a machine missing that extension.
