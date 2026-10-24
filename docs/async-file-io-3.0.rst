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
==================================================

The experimental Coroutine/Filesystem/ScopedFileTransfer reuses one scope-owned
ScopedFiles executor and AsyncConnection. Sending and receiving both operate on
at most 16 KiB at a time, with a 64 MiB cap per call. Outbound transfers suspend
on network write pressure and keep the connection's configured send budget.

The caller retains authority over path access, connection ownership, graceful
shutdown, destination truncation and atomicity. This class does not promise
file-system sandboxing, fsync durability or a working generic HTTP client.
