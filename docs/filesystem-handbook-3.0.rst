Asynchronous filesystem operations and streaming
================================================

The exact asynchronous guarantee
--------------------------------

Ordinary PHP filesystem calls are blocking. Runwire's supported
3.0 file backend uses a bounded `ProcessExecutor` with separate
CLI worker processes. The reactor interacts with nonblocking process
pipes and receives completion callbacks; it does not issue filesystem
reads or writes synchronously in a network callback.

No PHP native kernel-accelerated filesystem backend is currently
qualified or advertised by Runwire 3.0. It would be incorrect to label
the process executor as io_uring or native asynchronous disk I/O.
Execution remains subject to host process-spawn and filesystem limits.

Objects and ownership
---------------------

`AsyncFileSystem` is a facade over a caller-supplied executor.
`AsyncFileOperationsInterface` and `AsyncFileHandleInterface` define
operation contracts. `ProcessExecutor` controls worker count, pending
jobs, cancellation, drained notifications and lifecycle diagnostics.
`ScopedFiles` integrates file jobs with coroutine/task ownership.

.. code-block:: php

   <?php
   use Infocyph\Runwire\Executor\ProcessExecutor;
   use Infocyph\Runwire\Filesystem\AsyncFileSystem;
   use Infocyph\Runwire\Loop\SelectLoop;

   $loop = new SelectLoop();
   $executor = new ProcessExecutor($loop, 2, 32);
   $files = new AsyncFileSystem($executor);

   $files->read(
       '/safe/data.bin',
       4096,
       static function (string $bytes, ?Throwable $error): void {
           if ($error !== null) {
               // Report the actual filesystem failure to the owner.
               return;
           }
           // Consume a bounded binary result here.
       },
   );
   $executor->onDrained(static function () use ($executor): void {
       $executor->close();
   });
   $loop->run();

Do not copy this into a service expecting it to halt automatically:
the application should define operation completion, callback error
handling and worker shutdown boundaries. The executor is borrowed by
the file facade, not owned by a single file read.

Available operations
--------------------

.. list-table:: Operation and safety review
   :header-rows: 1
   :widths: 22 45 33

   * - Call
     - Purpose
     - Cautions
   * - `read`
     - Bounded binary read
     - Length, EOF, cancellation and max result size
   * - `write`
     - Offset-based binary write
     - Partial results, truncation assumptions, restart safety
   * - `stat`
     - File metadata retrieval
     - Snapshot can race with later open/delete
   * - `rename`
     - Rename path
     - Cross-device and durability caveats
   * - `unlink`
     - Remove named filesystem entry
     - Do not infer authorization from path string
   * - `cancel`
     - Cancel pending or queued work
     - Already dispatched worker operation may have side effects

A single binary read/write is limited to 1 MiB in the current facade.
Larger transfers must be chunked, not submitted as giant IPC messages.
Do not keep all chunks in memory before forwarding them; respect
downstream connection pressure and the actual file transfer quota.

Streaming and coroutine scopes
------------------------------

`ScopedFileTransfer` offers bounded chunked transfer behavior under
a coroutine scope, and `ScopedFiles` ties file operations to that scope.
The existing 3.0 guide describes 16 KiB chunks and a 64 MiB total
transfer policy for its documented path. Inspect the exact
constructor/default values before changing production limits.

A practical pipeline is:

.. code-block:: text

   inbound request -> validated bounded file path
       -> coroutine scope -> bounded process executor queue
           -> async read/write completion
               -> response writer with backpressure
                   -> drain and release executor/job state

The application must not read the next unbounded chunk while the peer
is no longer consuming the previous chunk. On client cancellation,
cancel the task and terminate any pending file jobs without leaving
stale reactor pipe watchers.

Filesystem threat boundaries
----------------------------

The async facade is **not** a sandbox or filesystem authorization
service. Before opening a user-supplied path, the application must
enforce its own allowed directory/tenant policy and defend against
symlink, traversal and time-of-check/time-of-use races. The backend
does not guarantee atomic replacement, crash-durable fsync, directory
fsync, cross-filesystem rename atomicity or a fully portable locking
contract.

A successful callback means the worker reported success for the
particular operation; it does not prove that another process cannot
modify that file immediately afterward.

Process availability and failure
--------------------------------

Some shared hosts disable process creation, process pipes or fork.
The bounded executor must report an unsupported capability instead
of falling back to blocking reactor-thread I/O. Queue saturation,
worker crash, callback exception, cancellation and stop/shutdown
require distinct observations. Keep a finite pending job ceiling.

Use the same trust discipline as for outbound networking: a callback
must complete once even when a cancellation race occurs; an inherited
active executor may not be operated from the wrong PID.

Benchmarking file I/O correctly
-------------------------------

Measure reactor p99 heartbeat latency and file completion latency
separately. Include worker count, queue depth, binary sizes, CPU/RSS,
IPC bytes, error counts and cancellation outcomes. Different
filesystems, page cache states and disk backends affect results.
A cached zero-byte file test is not evidence of sustained throughput.

.. code-block:: console

   php .ci/c3_filesystem_smoke.php
   vendor/bin/pest tests/AsyncFileBackendContractTest.php

The C3 file test demonstrates portable off-reactor completion; U15
performance certification still requires mixed network + file
workload under the frozen release baseline.

Related reading: :doc:`async-file-io-3.0`,
:doc:`concurrency-handbook-3.0` and
:doc:`performance-qa-handbook-3.0`.


What happens on a failed or cancelled write
-------------------------------------------

The queue accepts work before a worker finishes it. Cancellation
removes pending ownership but cannot assume that a write already
running in a child had no side effects. Therefore never blindly
resubmit a non-idempotent ``write``/``rename`` after an uncertain
completion; determine the file's actual state first.

For **reads**, use bounded offsets and handle EOF distinctly from a
cancellation error. For **writes**, decide whether a partial result is
acceptable and verify the final byte count. For **renames**, account
for cross-device failure and the absence of a durability guarantee.

.. code-block:: console

   vendor/bin/pest tests/AsyncFileSystemTest.php
   vendor/bin/pest tests/AsyncFileSystemSecurityTest.php
   vendor/bin/pest tests/AsyncFilesystemSocketFairnessTest.php

**When to choose synchronous I/O instead:** a one-off CLI tool without
a busy reactor may be faster and simpler with blocking PHP disk calls.
A long-running reactor with concurrent network peers should isolate
potentially blocking filesystem calls behind a bounded executor.
In both cases, untrusted pathname authorization belongs to the
application and operating-system sandbox, not to a string comparison.
