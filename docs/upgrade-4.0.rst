Runwire 4.0 upgrade guide
=========================

Runwire 4.0 removes native HTTP/3, QUIC, QPACK and the optional
php-quic extension integration. It is a breaking upgrade from 3.1.

Retained capabilities
---------------------

HTTP/1.1 and HTTP/2 client and server, HPACK, HTTP/1.1 WebSocket,
TCP/Unix streams, UDP datagrams, TLS and mTLS, process supervision,
coroutines, file operations, runtime adapters and application
lifecycle contracts remain in scope. The existing H1/H2 security,
framing, cancellation and flow-control fixes carry forward.

Deployment and migration
------------------------

* Terminate public HTTP/3 at an independent reverse proxy or CDN.
  Configure an HTTP/1.1 or HTTP/2 upstream to Runwire. Do not advertise
  Alt-Svc from Runwire itself.
* Remove ``ext-quic`` from deployment requirements. No replacement
  extension, FFI or QUIC emulation is required.
* Remove the ``http3`` constructor option and ``withHttp3()`` calls
  from Server definitions. Use the retained TCP/TLS configuration.
* Remove direct Http3OutboundClient, QuicClientOptions, QuicClientSession
  and QuicHandshakeProbe usage. Use Http1OutboundClient,
  Http2OutboundClient or HttpOutboundDispatcher.
* Remove the dispatcher QUIC and H3-session constructor arguments;
  use named ``h1Session`` or ``h2Session`` arguments.
* ProtocolVersion accepts only HTTP_1_1 and HTTP_2. ClientProtocolPolicy
  no longer takes a QUIC flag in negotiate(). Invalid ALPN or explicit
  HTTP/3 host metadata fails closed; do not relabel it as HTTP/2.
* Remove H3/QUIC capability flags, environment configuration,
  executor operations and H3/QPACK metrics. Update dashboards and
  positional constructor calls affected by removed parameters.

WebTransport is not supported. A normal reverse proxy does not translate
WebTransport streams or datagrams into HTTP/1.1 or HTTP/2 application requests.

Release gates
-------------

The versioned source audit records exact declaration deltas against the
published 3.1 baseline. Existing historical 2.1.2-to-3.0 evidence remains
available at its original revision. Run the full retained hosted matrix,
independent H1/H2 TLS reference, mixed TCP/UDP lifecycle, consumer
qualification, matched throughput/latency/RSS comparison and soak before
publishing. See :doc:`features` and :doc:`production-operations-3.0`.
