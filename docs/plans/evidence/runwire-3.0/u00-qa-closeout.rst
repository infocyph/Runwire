.. _runwire-u00-qa-closeout:

====================================================
U00 acceptance — executable lifecycle and topology
====================================================

U00 is a **design/baseline** batch only. Runwire 3.0 implementation is not
complete. The published comparator remains Runwire 2.1.2 at immutable SHA
``7e9bf20ad0737a187dfed77b723791b141b36378``.

**U00.04:** ``DialAttemptLifecycle`` validates 64 phase-pair transitions,
including permitted DNS/plain/TLS flow and forbidden premature adoption,
terminal re-entry, duplicate completion. The design and ownership diagrams
are in ``u00-dial-attempt-decision.rst``. Four PHPForge QA combinations
and PHPStan/Psalm analysis passed in Security & Standards run
`39907429067 <https://github.com/infocyph/Runwire/actions/runs/39907429067>`_.
Extended HTTP/3 and benchmark job conclusions are separately monitored.

**U00.05:** The approved unified ConnectionFactory architecture uses
one established transport buffer/read/write implementation. Pending DNS,
TCP and TLS stages retain socket ownership until validated handoff.
This is a **design gate only**: U02 and U03 must implement and verify actual
sockets, scope ownership and no second established I/O engine.

**U00.09:** The new ``benchmarks/release_topology_inventory.py``
captures the actual benchmark runner machine, kernel, CPU model, allowed
cpuset, physical-core server/generator separation, PHP/Python versions,
pinned 2.1.2 comparator and five-pair workload/soak parameters.
Both PHP8.4 and PHP8.5 package jobs passed the enforced JSON preflight
in `Release Candidate #39907036881 <https://github.com/infocyph/Runwire/actions/runs/39907036881>`_.

* `PHP 8.4 topology artifact <https://github.com/infocyph/Runwire/actions/runs/39907036881/artifacts/12481640404>`_
* `PHP 8.5 topology artifact <https://github.com/infocyph/Runwire/actions/runs/39907036881/artifacts/12481675409>`_

These are real CI-runner measurements, not a claimed performance speedup.
Actual generator headroom, strict candidate matching, sustained comparison
and final 3.0 certification remain separate U15/U17 acceptance gates.

**Documentation policy:** Runwire's published Sphinx manual follows the
InterMix pattern ``docs/conf.py``, ``docs/index.rst`` and
``.readthedocs.yaml``, but intentionally excludes MyST. All released
manual pages must use native ``.rst``. The strict
`Sphinx run #39907428632 <https://github.com/infocyph/Runwire/actions/runs/39907428632>`_
passed. Legacy public Markdown guides must still be converted under U16
before the full documentation checklist can close.
