# Runwire 2.1.2 patch candidate

Status: unpublished. This note prepares the patch; it does not authorize a tag
or publication.

Swoole/OpenSwoole `Runtime::serveApplication()` previously created the
application and immutable runtime context in the parent before the host forked.
Two-worker serving therefore passed the parent's PID and inherited application
resources into children. A real Swoole 6.2.3 probe reproduced worker PIDs 7/8
with runtime PID 1; an independent Runwire-only probe confirmed the defect.

The factory now runs inside each actual worker's startup callback. The built-in
application used by `Runtime::serve()` follows the same boundary. Workers and
replacements receive process-correct contexts, native worker slots and fresh
metrics. Request identity stays bound to the exact factory context. Worker
drain/shutdown is owned and completed once, retaining startup/shutdown failures.
The application/factory interfaces, native listener paths and other host driver
selection remain unchanged.

Patch compatibility: existing public signatures, runtime requirements,
configuration defaults, context forwarding and application/factory interfaces
are unchanged. The existing `SwooleDriver::run($application)` path remains
supported; its caller still owns the process-safety of that prebuilt object.
The additive `runFactory()` path lets the existing high-level APIs construct
applications at their correct worker boundary without changing caller code.
Swoole factory side effects execute once per worker, including replacements;
host-wide setup belongs before serving rather than inside an application factory.

The patch also resolves 38 pre-existing cognitive-complexity findings exposed
by the current 1.3.0 analyzer. Private helpers and simpler control flow retain
validation order, buffer limits, protocol fairness, cancellation and shutdown
semantics. No production classes, required dependencies, options or existing
public/protected signatures were added or changed for this quality maintenance.
An AST comparison preserves all 2,022 existing public/protected contracts.

Regression coverage includes worker-local creation, failure before creation,
combined startup/shutdown failure and independent replacement state. The
existing PHP 8.4/8.5 Swoole/OpenSwoole workflow now exercises both high-level
host APIs with two real workers, eight concurrent PHP clients, no application
creation in the parent and one drain/shutdown per worker. The acceptance runner
uses PHP only; no Python installation is needed.

Local real Swoole acceptance passes both APIs (64 concurrent requests each).
A Foundation integration probe passes 40 concurrent requests across both
workers, unique request IDs and HTTP failure recovery using the candidate
source through a test-only loader; installed vendor sources are untouched.
Local PHP 8.5.11 regression tests pass: 556 tests / 3,654 assertions. PHPStan
with cognitive-complexity 1.3.0 passes without exclusions or threshold changes.
The full detailed quality suite and final release guard also pass in the
provisioned PHP 8.5.11 container. Hosted acceptance must cover the completed
patch revision before publication. Composer reports zero security
advisories and one non-blocking abandoned development dependency
(`doctrine/annotations`, through PHPForge's PHPBench dependency).
No Foundation-wide throughput or release-readiness claim follows from these
functional probes.
