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
The complete U14.06 symlink/TOCTOU audit remains open.
