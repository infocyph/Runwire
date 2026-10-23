=======================
Installing current Runwire
=======================

The current stable release supports 64-bit PHP 8.4+.

.. code-block:: console

   composer require infocyph/runwire

The existing server runtime supports HTTP/1.1, HTTP/2, optional QUIC/HTTP/3,
WebSocket serving, TCP/Unix stream servers, UDP, coroutines and supervision.
Outbound clients, WebTransport, autoscaling and async filesystem are part of
the **planned 3.0 target**, not currently shipped.

See :doc:`features` for the complete feature-status inventory.
