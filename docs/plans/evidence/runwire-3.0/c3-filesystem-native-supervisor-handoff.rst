C3 — Native Filesystem and Supervisor Acceptance Handoff
=========================================================

Release scope
-------------

Runwire 3.0 only, existing ``plan/runwire-independent-ecosystem-roadmaps`` branch and
draft pull request #9. C3 contains exactly nine existing U-task IDs:
U11.03, U11.10, U12.02, U12.04, U12.05, U12.06, U12.08, U12.09, U14.04.
The seven previously open IDs require exact-head independently green QA and
task-specific proofs before checklist promotion; U11.10 and U14.04 were
already accepted on their earlier immutable sources.

Capability boundary
-------------------

U11.03 does not claim kernel-native asynchronous filesystem acceleration.
The file API qualifies no native filesystem accelerator on supported PHP
targets; the tested bounded ProcessExecutor performs portable work off the
event loop. No mandatory optional extension, synchronous reactor-path
``file_get_contents()`` or fake native-async contract is introduced.
WebTransport is deliberately excluded from the 3.0 scope. HTTP/3 and
native QUIC streams remain separate supported transports.

Code-level ownership and lifecycle proofs
-----------------------------------------

* U12.02: listener PID-bound *after* event loop activation while
  prebound prefork listeners can attach independent child loops.
  Inherited active listeners, dialers, outbound pools, QUIC sessions and
  process executors reject child-side use and teardown.
* U12.04: the native supervisor's ``SupervisorAutoscaler`` owns measured
  window decisions and monotonic cooldown. The master alone forks, drains
  and retires slots, with no simultaneous current owner for one slot.
* U12.05: native H1/H2/H3/TCP/UDP independent peers verify bodies and
  cancellation boundaries across SIGKILL, replacement, completed reload,
  manual recycle and SIGTERM while admitted streams are in flight.
  For H2, admission means independently observed server HTTP response
  headers, not client-side submission; all eight stream bodies must finish.
  A separate H3 wave admits eight sibling requests and cancels a ninth.
* U12.06: ``WorkerContext`` tracks dialers, TCP connections, pools, QUIC
  sessions, bounded executors, background coroutines and recurring tasks;
  fault-isolated shutdown still tears down independent resources and
  preserves retryable timer cancellation, with no surviving child PID,
  watcher or terminal FD leak on the native smoke fixture.
* U12.08: real concurrent SIGKILL, restart-budget, scale-in/out, rolling
  reload, recycle and graceful stop scenarios, with no slot ownership
  overlap or orphaned child processes.
* U12.09: a native 120-second autoscale process-tree soak per PHP version
  samples CPU, resident memory, open descriptors and loop p99; it is
  a C3 sustained lifecycle diagnostic, **not** the 1800-second U15
  release certification. The live HTTP fixture requires every request
  attempted after readiness to complete successfully.

Native performance and correctness reference
--------------------------------------------

Dual-version C3 native run:
https://github.com/infocyph/Runwire/actions/runs/40486393653

* PHP 8.4 and PHP 8.5 each returned 136/136 correct HTTP/1 requests.
* No observed sub-cooldown oscillation; both scale directions witnessed.
* Extended soak: 30 (PHP 8.4) and 29 (PHP 8.5) worker transitions.
* Sampled native loop p99 jitter: 0.054 ms (8.4), 0.043 ms (8.5).
* No surviving worker on fixture shutdown.
* These numbers are **not** matched 2.1.2-vs-3.0 performance or
  sustained production RPM and do not satisfy U15 acceptance.

Exact-head full QA gates
------------------------

Before C3 handoff is final, require all routine Runwire workflows
(Source Audit, Docs/Sphinx, Release Candidate, Portable Native,
Hosted Runtime, Swoole/OpenSwoole, HTTP/2 Independent Reference,
Benchmarks and Security & Standards), plus C3 Native Supervisor Smoke,
all completed SUCCESS on the same immutable branch source.
Security & Standards includes both PHP 8.4/8.5 native QUIC, the
independent H1/H2/H3/TCP/UDP stream/admission/shutdown fixture, four
PHPForge QA variants, two static analyzers and secure clean install.

Relevant source-at-head acceptance run (verify completion independently):
https://github.com/infocyph/Runwire/actions/runs/40490979414
https://github.com/infocyph/Runwire/actions/runs/40490978809

No CI suppression, PHPForge repository edit, WebTransport capability
restoration, tag, merge or release is authorized here.

Remaining beyond C3
-------------------

C4 retains independent final matched release benchmarks, extended
1800-second CPU/RSS/FD/process tree soaking, and full hosted
documentation preview. C5 separately certifies and hands off a frozen
exact-head release candidate; closing C3 does not close C4 or C5.
