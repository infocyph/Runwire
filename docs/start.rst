Installation and first validation
=================================

Release status
--------------

Runwire 2.1.2 is the currently published stable package. Runwire
3.0 is a development branch with a substantially expanded
asynchronous runtime; it has **not yet been published**. The minimum
supported PHP version for the currently documented package is 8.4
on a 64-bit runtime. PHP 8.4 and 8.5 are exercised in development QA.

Install the published package with Composer:

.. code-block:: console

   composer require infocyph/runwire

This command installs the published version, **not** 3.0's
development-branch features. To review or test unreleased 3.0,
use a checked-out Runwire development branch and its own Composer
dependencies; do not assume the current stable package exposes
`Http3OutboundClient`, native autoscale or the filesystem facade.

Quick local inspection
----------------------

.. code-block:: console

   php -v
   php -m
   composer validate --strict

For a development checkout with dev dependencies installed:

.. code-block:: console

   composer install --no-interaction --prefer-dist --no-progress
   php tests/fixtures/portable_native_acceptance.php

The portable acceptance fixture checks real local HTTP, TCP,
Unix and UDP peer traffic. It does not require native QUIC and
does not prove prefork capabilities or production performance.

Choose the relevant guides
--------------------------

* For runtime choice, ownership and lifecycle:
  :doc:`handbook-3.0` and :doc:`runtime-lifecycle-3.0`.
* For actual server/client configuration:
  :doc:`configuration-reference-3.0`.
* For HTTP/1.1, HTTP/2, HTTP/3, WebSocket and outgoing TLS:
  :doc:`inbound-handbook-3.0` and :doc:`outbound-handbook-3.0`.
* For native worker orchestration:
  :doc:`supervision-handbook-3.0`.
* For deployment and host support:
  :doc:`production-operations-3.0` and :doc:`host-compatibility-3.0`.
* For an automatic source-linked API inventory:
  :doc:`api-reference-3.0`.

Host and extension cautions
---------------------------

Portable Select, native prefork, FPM/FrankenPHP/RoadRunner and
Swoole/OpenSwoole host-owned runtimes have distinct ownership
contracts. Native prefork supervision requires appropriate PCNTL
and POSIX support. HTTP/3 requires a usable native QUIC/TLS backend;
WebTransport is explicitly excluded from 3.0. Async file and
off-loop DNS workers require process execution capability.

See :doc:`features` for the complete feature/host inventory and
:doc:`security-handbook-3.0` before configuring untrusted endpoints.
