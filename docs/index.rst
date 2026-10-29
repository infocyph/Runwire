.. _runwire-index:

===========================
Runwire developer handbook
===========================

**Runwire 3.0 release-candidate manual.** This manual describes the
checked-out 3.0 source and its supported runtime contracts.
Use the version selector on the documentation host for historical releases.

**Source revision:** |runwire_source_revision|.

The handbook is written for application developers, native and
host-owned runtime integrators, operators, performance engineers
and security reviewers. Features requiring optional PHP extensions or host capabilities are
identified in the relevant chapters.

If this is your first visit, read :doc:`start` and
:doc:`handbook-3.0`. For executable tests and APIs, use
:doc:`tutorials-3.0` and :doc:`api-reference-3.0`.

.. toctree::
   :maxdepth: 2
   :caption: Start here

   start
   features
   handbook-3.0
   quickstart-lab-3.0
   architecture
   runtime-lifecycle-3.0
   configuration-reference-3.0

.. toctree::
   :maxdepth: 2
   :caption: Networking and asynchronous application development

   inbound-handbook-3.0
   outbound-handbook-3.0
   transport-guide-3.0
   protocols-3.0
   protocol-failure-atlas-3.0
   connection-policies-3.0
   concurrency-handbook-3.0
   coroutine-network-3.0
   filesystem-handbook-3.0
   async-file-io-3.0
   tutorials-3.0

.. toctree::
   :maxdepth: 2
   :caption: Operation, security and verification

   supervision-handbook-3.0
   host-compatibility-3.0
   production-operations-3.0
   operations-exercises-3.0
   observability-handbook-3.0
   troubleshooting-handbook-3.0
   security-handbook-3.0
   performance-qa-handbook-3.0
   performance-lab-3.0
   performance-security-3.0

.. toctree::
   :maxdepth: 2
   :caption: API, migration and contributing

   api-reference-3.0
   migration-playbook-3.0
   compatibility-audit-3.0
   migration-3.0
   documentation-maintenance-3.0

**Key capability exclusions:** WebTransport is out of scope,
nested TLS for an HTTPS proxy plus HTTPS origin is unsupported,
and the asynchronous filesystem implementation uses bounded
process-worker I/O rather than qualified kernel-native AIO.
HTTP/3 requires a compatible native QUIC implementation.

All public Sphinx pages are native reStructuredText. Internal release-history plans are not part of the 3.0 documentation tree. The generated API index is built from
the checked-out Runwire sources, so published code links describe
the source revision being built.
