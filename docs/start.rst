Installation and first validation
=================================

Release status
--------------

This manual targets Runwire 3.0 on PHP 8.4+ (64-bit). During candidate
review, install from the checked-out source. The ordinary Composer command
below installs the latest published stable version, which may differ from
this manual until 3.0 is tagged and published.

.. code-block:: console

   composer require infocyph/runwire

For 3.0 testing, check out the 3.0 candidate revision and run
``composer install`` locally. Do not treat an unreleased candidate as
an already published package.

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
