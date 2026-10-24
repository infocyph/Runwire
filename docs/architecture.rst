============================
Unified runtime architecture
============================

The future 3.0 architecture will use one established-connection owner for
both accepted and dialed byte streams. DNS resolution, pending TCP connects,
TLS verification, QUIC sessions and UDP datagrams require separate semantics.

Runwire shall not claim asynchronous DNS or filesystem support if the
operation blocks the event loop. Supported native and host-owned execution
environments must advertise truthful capability differences.

This architecture remains a **development target** until its acceptance
tasks pass. See :doc:`features` for release status.
