.. _u01-qa-closeout:

=====================================================
Runwire 3.0 U01: event loop and executor QA closeout
=====================================================

Source snapshot: ``a5a900b701ef67035218402249ec66a689e4d520``.
Branch: ``plan/runwire-independent-ecosystem-roadmaps``.
Pull request: https://github.com/infocyph/Runwire/pull/9

Acceptance and scope
====================

* U01.01/.02/.08 retain the audited Select/ext-event/Swoole ownership,
  watcher-cancellation and non-nested host reactor contracts.
* U01.03 caps deferred callbacks at 256 per tick and preserves bounded timer
  batches; a 1024-callback storm with a real local socket and timer proves
  progress before the backlog drains.
* U01.04 uses monotonic deadlines, taking the earlier of explicit deadline
  and inherited token deadline. Completion, expiry and shutdown are idempotent.
* U01.05/.06 deliver bounded worker capacity, limited queue, explicit
  cancellation, true subprocess DNS and file I/O with nonblocking parent
  pipes. No synchronous fallback is taken in the event-loop thread.
* U01.07 tests active and queued cancellation, close/drain, immediate dead
  worker, subprocess restart, token unsubscribe and no retained workers.
* U01.09 records 32 off-loop worker jobs with CPU, memory, p50/p95/p99
  latency, scheduler ticks, correctness and resource-count observations.

Immutable CI evidence
=====================

* PHP 8.4/8.5 prefer-stable and prefer-lowest PHPForge QA: all four
  successful in Security and Standards run 39913487501:
  https://github.com/infocyph/Runwire/actions/runs/39913487501
* PHPStan/Psalm analysis: PHP 8.4 and 8.5 analysis jobs successful
  in the same run.
* Core benchmark profile: both PHP 8.4 and PHP 8.5 successful in run
  39913487092: https://github.com/infocyph/Runwire/actions/runs/39913487092
* PHP 8.4 executor evidence:
  https://github.com/infocyph/Runwire/actions/runs/39913487092/artifacts/12483559225
* PHP 8.5 executor evidence:
  https://github.com/infocyph/Runwire/actions/runs/39913487092/artifacts/12484125922
* Source audit 39913486870, release candidate 39913487240, portable
  native 39913487061, hosted acceptance 39913486975, Swoole/OpenSwoole
  39913487064 and Sphinx 39913487105 each passed:
  https://github.com/infocyph/Runwire/actions/runs/39913486870

Evidence boundaries
===================

The two benchmark profiles represent parent PHP process CPU and peak PHP
allocated bytes only; child worker process-tree accounting is not asserted.
Matched production 2.1.2-to-3.0 throughput and complete process-tree
soak remain U15 and U17. Actual Runwire 3.0 outbound transport/connection
unification remains U02 onward. HTTP/3 and the extended adaptive HTTP/2
workflow tails were running when this evidence was composed; do not infer
overall workflow success from individual successful jobs.
