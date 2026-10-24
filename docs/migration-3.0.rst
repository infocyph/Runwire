Runwire 2.1.2 to 3.0 development migration guide
==================================================

This guide is an implementation-aligned **draft** for unreleased Runwire 3.0.
The published stable release is still 2.1.2. No source incompatibility
or new public API should be treated as shipped until the final freeze and
public-manifest comparison in U16.12/U17.01 have passed.

Core migration boundary
-----------------------

The high-level ``Runtime::create()->listen()->run()`` server model remains
the recommended application entry point. Existing server connection and
response-writer ownership is preserved while 3.0 adds a separate outbound
dial path. Avoid adapting accepted server streams to outgoing connections
without explicitly handing their callback owner and cancellation scope off.

.. code-block:: php

   use Infocyph\Runwire\Loop\SelectLoop;
   use Infocyph\Runwire\Network\Dialer;

   // In development: this outbound dialer complements 2.1.2 servers.
   $loop = new SelectLoop();
   $dialer = new Dialer($loop);
   $dialer->connect('tcp://127.0.0.1:8080',
       static function ($connection, ?Throwable $error) use ($loop): void {
           $connection?->abort();
           $loop->stop();
       });
   $loop->run();
   $dialer->close();

TLS and client protocol policy
------------------------------

Outgoing TLS is deliberately separate from server ``TlsOptions``. 3.0
introduces ``TlsClientOptions``, with certificate/hostname verification,
mandatory original peer identity and explicit ALPN. Do not pass a server TLS
context into an outbound client, suppress verification, retry HTTPS as
plaintext, or forward authentication headers to redirected origins.

A direct ``Http1OutboundClient`` requires the request policy to permit
HTTP/1.1 before DNS/dial admission; an H2-only/H3-only preferred policy
must fail closed. The ``Http1PolicyClient`` redirect helper is optional,
bodyless GET/HEAD only, and does not supply a cookie jar or transparent
HTTP/2/3 fallback.

Async filesystem and resource ownership
---------------------------------------

New ``AsyncFileSystem`` delegates bounded read/write/stat/rename/unlink
to a ``ProcessExecutor``. Application code must construct and own an
executor on an appropriate event loop and explicitly close it. A worker-
checked ``rootDirectory`` is opt-in, not a race-free filesystem sandbox.
Migrations must not treat PHP's normal synchronous file functions as
nonblocking merely because they execute inside a coroutine.

``BoundedConnectionPool`` adds lease permits, FIFO waiter caps and
identity-scoped idle storage, but callers still dial independently and
release every lease on both success and failure. Stream relays preserve
cleartext half-close and cancel their own callbacks; a TCP half-close is
not an authenticated TLS close_notify.

Compatibility and release verification
--------------------------------------

Native prefork workers require PCNTL/POSIX; PHP-FPM, FrankenPHP,
RoadRunner and Swoole continue to own their host-specific listener/worker
lifecycle. Optional ext-event, ext-swoole and ext-quic are not mandatory
Composer dependencies. HTTP/2 and HTTP/3 outbound clients, CONNECT/SOCKS5,
WebTransport and full release benchmarks are **not complete** yet.

The final migration acceptance requires an exact reflected 2.1.2 versus
3.0 manifest diff for removed/renamed symbols, changed public parameters,
enum cases, return types and exception behavior. This page intentionally
does not claim that incomplete audit is finished.

.. code-block:: console

   php tools/public-api-manifest.php src /tmp/runwire-current-api.json $(git rev-parse HEAD)
   vendor/bin/pest tests/Plan3TrackerSynchronizationTest.php

The 2.1.2 baseline manifest is pinned independently under the development
tracker; never relabel current source as the historical 2.1.2 revision.

Automated source-signature comparison (development)
---------------------------------------------------

Run the source-only API manifest exporter against an unchanged checkout of
published 2.1.2 and the 3.0 development revision, then compare them with
the deterministic CLI diff tool. Source fingerprints do **not** prove
behavioral compatibility, exceptions at runtime, or release readiness.

.. code-block:: console

   php tools/public-api-diff.php /path/to/2.1.2-api.json /path/to/3.0-api.json

The output reports removed types, removed methods, changed signatures,
constant and enum-case changes, and newly declared types, without loading
optional PHP extensions or invoking network APIs. The final release audit
must still document each intentional breaking change, review callback and
exception behavior and pin both immutable source revisions. **U16.12 and
U17.01 are not yet complete.**

Public property inventory note
------------------------------

New schema-v1 manifest exports include ``publicProperties`` declarations and
constructor-promoted fields. The pinned historical 2.1.2 source manifest was
created before this optional field existed. The diff CLI therefore reports
``propertyInventoryCompared: false`` when comparing against that older archive;
it **does not** assert that removed properties were audited in that case.
Before U16.12 acceptance, regenerate a 2.1.2 source-only manifest with the
same exporter version and repeat the immutable baseline comparison. Property
and method behavior still requires maintainer-reviewed migration examples.
