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


Native autoscale observation hysteresis (development)
-----------------------------------------------------

The internal ``Supervisor\Internal\AutoscaleWindowController`` gates the
existing pure worker target calculator behind a monotonic observation clock,
the policy sampling cadence, repeated pressure or idle windows and cooldown.
It prevents alternating overload/idle windows from oscillating the target.
Operator-fixed worker counts and min/max safety recovery remain immediate.

This **does not implement live prefork scale-out, graceful scale-in or drain**.
Those require separate supervisor ownership, worker readiness, real metrics,
fault injection and sustained process-tree checks for U12.04/U12.08/U12.09.
