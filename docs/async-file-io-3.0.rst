=========================================
Runwire 3.0 development: async file I/O
=========================================

This document describes **unreleased development APIs**, not the published
Runwire 2.1.2 package. Production support is conditional on full U11 QA.

The preliminary ``Filesystem\AsyncFileSystem`` delegates each bounded
operation to a reusable ``Executor\ProcessExecutor`` running blocking PHP
filesystem calls inside separate worker processes. The event loop only
reads and writes nonblocking IPC pipes, never calling ``file_get_contents``
or ``fwrite`` directly in the reactor thread. Worker count and queue length
are explicitly configured. Invalid operations, cancellations and deadlines
complete callbacks exactly once.

The current development facade offers bounded ``read``, offset ``write``,
``stat``, ``rename``, ``unlink`` and ``cancel``. A single binary read
or write is limited to 1 MiB. Larger transfers must be chunked by callers.
The facade borrows the supplied executor; callers close the executor on
scope/worker shutdown.

.. code-block:: php

   <?php
   use Infocyph\Runwire\Executor\ProcessExecutor;
   use Infocyph\Runwire\Filesystem\AsyncFileSystem;
   use Infocyph\Runwire\Loop\SelectLoop;

   $loop = new SelectLoop();
   $executor = new ProcessExecutor($loop, 2, 32);
   $files = new AsyncFileSystem($executor);

   $files->read('/safe/data.bin', 4096, function (string $bytes, ?Throwable $error): void {
       // Check $error before consuming $bytes.
   });
   $executor->onDrained(static function () use ($executor): void {
       $executor->close();
   });
   $loop->run();

**Important limitations:** This is not kernel async I/O, filesystem
sandboxing, atomic durable replacement, cross-device atomic renaming,
symlink/TOCTOU protection, or an indefinitely open file-descriptor API.
Native acceleration and file-to-network streaming are separate tasks.
Untrusted path handling needs explicit U11.09 access policy. A successful
``fflush`` does not imply durable ``fsync`` across power failure.


Bounded file to network streaming (3.0 development)
========================================================

The experimental Coroutine/Filesystem/ScopedFileTransfer reuses one scope-owned
ScopedFiles executor and AsyncConnection. Sending and receiving both operate on
at most 16 KiB at a time, with a 64 MiB cap per call. Outbound transfers suspend
on network write pressure and keep the connection's configured send budget.

The caller retains authority over path access, connection ownership, graceful
shutdown, destination truncation and atomicity. This class does not promise
file-system sandboxing, fsync durability or a working generic HTTP client.

Rename, flush and crash durability boundaries
=============================================

``AsyncFileSystem::write`` performs a worker-local buffered write and an
``fflush`` before closing the file handle. It does **not** call ``fsync`` or
``fdatasync``, and its success means the requested bytes were accepted by
the worker/OS, not that data survived power loss. ``rename`` delegates to
ordinary host ``rename()`` and reports its exact success or failure.

On supporting filesystems, a same-filesystem rename may give atomic name
visibility, but Runwire 3.0 does not guarantee atomic overwrite across file
systems or network mounts, and provides no crash-durable replacement API.
Cross-device rename may fail. A crash-safe replacement requires a verified
same-directory temporary file, complete and checked writes, file
``fsync``, a supported atomic rename and directory metadata synchronization.
The current process worker performs none of that full transaction.

Failure is returned via the worker completion error; there is no hidden
fallback to a copy/delete that could falsely imply atomicity. This is an
explicit unsupported capability, not an invitation to assume that
``fflush``/``rename`` alone guarantee persistence. Path/symlink/TOCTOU
and sandbox authorization require separate U11.09/U14.06 verification.

Portable open-file API contract (development)
----------------------------------------------

The unreleased 3.0 source declares a future ``AsyncFileOperationsInterface``
for open, read, write, stat, rename, unlink and cancellation. An asynchronous
open must deliver a worker-owned ``AsyncFileHandleInterface`` through a
completion callback. ``FileAccessMode`` defines separate read/write roles.
No blocking native stream resource may escape to the reactor.

These interfaces specify an API, **not** a working open-file backend.
The existing ``AsyncFileSystem`` handles only short-lived worker-local
read/write/stat/rename/unlink and does not currently implement open.
Operation callbacks must be exactly once, error-aware, bounded by caller
quotas, and subject to inherited cancellation/deadlines.

.. code-block:: console

   vendor/bin/pest tests/AsyncFileApiContractTest.php

Restricted worker-side paths (development)
------------------------------------------------

``AsyncFileSystem($executor, rootDirectory: '/srv/app/data')`` enables an
optional canonical-root boundary for read, write, stat, rename and unlink.
Each subprocess rejects ``..`` traversal, symlink path components, missing
parents and paths outside the configured absolute Unix directory before
performing filesystem I/O. Restricted checks remain entirely off the reactor.
Without a configured root, the historical host-permission semantics remain.

This is **not** a race-free filesystem sandbox: another process may swap
path components between worker-side validation and ``fopen``/``rename``.
Use operating-system isolation and a separately audited descriptor-relative
openat2 or equivalent backend for adversarial multi-user writable directories.
The U14.06 worker-side path/permission audit now covers common adversarial
inputs and documents the unresolved concurrent-mutation TOCTOU boundary.
That acceptance is **not** a promise of kernel-enforced sandboxing.


Adversarial filesystem path and permission checks
-------------------------------------------------

The development security suite ``tests/AsyncFileSystemSecurityTest.php``
executes real worker-backed operations against lexical parent traversal,
off-root paths, leaf and ancestor symlinks, symlinked roots, unreadable and
unwritable leaves, and prohibited rename/unlink/stat operations. It verifies
that the original outside file and allowed file remain unchanged, and that
all worker handles/watchers are retired.

The exact W96 PHPForge security/functional matrix passed these worker tests.
That acceptance verifies checked path and permission cases only.
It does **not** eliminate a time-of-check/time-of-use race if another
process can rename or replace path components between validation and the
subsequent PHP filesystem syscall. Configure filesystem isolation and refuse
untrusted mutable path roots until descriptor-relative access is supported.

Worker resource profiling (development only)
--------------------------------------------

On PHP CLI environments with nonblocking subprocess pipes, the bounded
benchmarks/async_file_worker_profile.php exercise writes, verifies,
reads and deletes isolated files through two process-backed workers.
It records event-loop heartbeat ticks, queue pressure, validated throughput
in bytes per second, peak process RSS and heap. Separate PHP 8.4/8.5
benchmark artifacts are diagnostic only: no published 2.1.2 filesystem
throughput comparison or release certification is implied. This does not
provide durable fsync or a race-free path sandbox.

.. code-block:: console

   php benchmarks/async_file_worker_profile.php 32 16384

One-versus-two worker sensitivity (development only)
---------------------------------------------------

The worker-backed file profile now accepts a third numeric argument, a
bounded worker count of 1–4. The CI benchmark jobs execute identical
32 by 16 KiB byte-verified workloads with one and two PHP worker processes.
Version-specific JSON records throughput, event-loop heartbeats, queue
pressure, utilization, and parent-only RSS. Matched comparison artifacts
record a ratio but impose no invented minimum speedup. These runs do not
certify native asynchronous file I/O or release performance.
