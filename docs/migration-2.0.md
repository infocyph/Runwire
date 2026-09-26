# Migrating from Runwire 1.x to 2.0

Runwire 2.0 tightens lifecycle ownership, capability reporting, resource admission, protocol fairness, and native event-loop behavior. The major-version boundary is primarily about stricter contracts and safer persistent-runtime behavior rather than changing the basic Runtime, Server, HttpRequest, or response-writing model.

## Upgrade checklist

1. Require Runwire 2.0 in the consuming application or framework and run the consumer's full persistent-runtime test suite.
2. Rebuild any custom ResponseWriterInterface implementation against the 2.0 interface and implement exactly-once terminal notification.
3. Treat runtime capabilities as enabled integration facts, not as theoretical host-product features.
4. Remove host-side per-request garbage collection that duplicates Runwire application-lifecycle GC ownership.
5. Recheck native capacity assumptions: ext-event is the scalable native loop when available; the portable SelectLoop fallback is deliberately bounded.
6. Recheck request, stream, queued-byte, coroutine, and connection limits against the application's measured workload before raising them.
7. Exercise reset failure, cancellation, late streaming completion, reload/recycle, and host-worker retirement paths before production rollout.
8. Re-run process execution tests if the application launches child processes, especially when descendants may retain inherited pipes or outlive the direct child.

## Response completion and persistent request lifetime

Runwire 2.0 treats handler return and request completion as separate events.

A request remains active until its response writer reaches a terminal state or the request context is cancelled, and all request-owned coroutine work has settled. Cleanup, reset, lifecycle metrics, lifecycle GC, and admission release happen before the context reports completion. Native worker completion counters, recycling, and retirement observe this completed lifecycle, including a reset failure discovered after the response ends.

Custom `RuntimeApplicationInterface` implementations must call `$request->context->complete()` after owned work, cleanup, health-state updates, and admission release. Rejected requests must also complete their context. Ending the response or initiating cancellation alone does not mark the worker request complete. The first-party `RuntimeApplication` handles this contract automatically.

Custom response writers must support the terminal-observer contract and notify observers exactly once when no more response work can be accepted. Temporary write pressure is not terminal.

Declared response body lengths are validated against accepted logical body bytes. HEAD and body-forbidden status semantics are enforced consistently across first-party native and host writers.

## Reset failure and worker retirement

A failed request reset makes the persistent application unsafe for reuse. Runwire stops new admission and retires the owning worker where that runtime exposes a retirement primitive.

Consumers must not catch a reset failure and continue serving the same persistent application instance. Framework integrations should keep request-local resetters deterministic, bounded, and safe to invoke on handler failure and cancellation paths.

## Capability reporting

RuntimeCapability checks now describe capabilities that are usable in the selected and enabled Runwire configuration.

Do not use a positive capability result merely because a host product could support that feature in some other configuration. HTTP/2, HTTP/3/QUIC, WebSocket, reload, recycle, and coroutine-related claims are reported only when the selected integration can actually provide them.

Prefer capability checks over driver-name branching for generic behavior.

## Native event loops and connection capacity

Runwire 2.0 selects the scalable ext-event backend when it is available. SelectLoop remains the portable fallback and intentionally uses a conservative native connection ceiling rather than pretending to scale beyond select() descriptor limits.

Applications that require high native connection counts should install and validate ext-event in the deployment image. Do not raise portable fallback limits to bypass descriptor safety.

Application code should continue programming against LoopInterface.

## Coroutines

Request-scoped coroutines attach to the loop already owned by the native worker or supported host integration. They no longer need to drive a nested loop from inside request dispatch.

Code using CoroutineRequestHandler should allow Runwire to attach the request runtime to the owning loop. Standalone CoroutineRuntime run/runRequest operations remain appropriate only when that runtime owns loop driving.

Task, ready-queue, waiter, and per-turn resume limits remain bounded. Blocking PHP APIs are still blocking and do not become asynchronous merely because they are called from a Fiber.

## Resource admission and protocol fairness

Runwire 2.0 adds worker-wide resource accounting in addition to protocol-local limits. The default policy includes bounded active requests/streams and a shared queued-byte budget.

HTTP/2 frame processing and HTTP/3 control/request progress are bounded per event-loop turn. HTTP/2 and HTTP/3 also retain their protocol-specific stream, compression, buffering, and flow-control limits.

Treat limit changes as capacity-planning decisions. Validate higher values with the sustained benchmark and soak procedures in [benchmarks.md](benchmarks.md).

## Host runtime ownership

FPM, FrankenPHP, RoadRunner, and Swoole/OpenSwoole continue to own their host process/listener/event-loop concerns. Runwire owns the application/request lifecycle after dispatch.

Runwire lifecycle policy is the single owner of per-request lifecycle GC. Host adapters must not add an unconditional second gc_collect_cycles() after every request.

Worker retirement is requested only when the host integration exposes a supported current-worker stop/replacement mechanism.

## Process execution

Process termination now preserves process-tree ownership more deliberately. On POSIX systems Runwire uses process-group ownership when available and closes races where descendants are spawned before the parent-side group assignment completes.

If application commands intentionally daemonize or detach descendants, verify that their lifetime is compatible with Runwire's owned-process policy. Avoid relying on descendants inheriting request pipes indefinitely.

ProcessRunner remains synchronous. Non-blocking pipes are an implementation detail and do not make command execution asynchronous.

## Security and transport behavior

Runwire 2.0 preserves explicit TLS verification settings rather than replacing caller policy. Live Unix-domain sockets are not silently unlinked. Protocol error responses continue to avoid exposing internal exception text.

HTTP/1 parsing, HTTP/2 compression/framing, HTTP/3 QPACK/control streams, UDP callbacks, and host response writers all use stricter bounded failure behavior covered by the 2.0 regression matrix.

## New bounded 2.0 surfaces

Two reviewed candidates were retained after their correctness, resource, interoperability, performance, and exact-head quality gates passed.

### Bounded stream-to-response transfer

`ResponseTransfer::stream()` copies an already-authorized stream through the existing `ResponseWriterInterface` contract. It requires a `CoroutineScope`, cooperates with cancellation, yields after bounded work, waits for writer drain under backpressure, and closes the source by default. The application still owns response status and headers.

For resources that must remain open, pass `closeSource: false`; Runwire restores blocking mode when it changed that mode for the transfer. Do not use the helper as an authorization or path-validation layer.

### Native WebSocket serving

`WebSocketUpgrade::accept()` and `WebSocketSession` provide bounded RFC 6455 serving on the native HTTP/1.1 path. The upgrade validates version/key/upgrade framing, client masking, UTF-8/control-frame rules, frame/message ceilings, fragmentation, ping/pong, close deadlines, slow-reader backpressure, and worker-wide byte accounting.

Browser requests carrying an `Origin` header are rejected unless the application supplies an explicit origin policy. Origin checks complement application authentication; they do not authenticate non-browser clients.

Runwire 2.0 does not advertise HTTP/2 or HTTP/3 WebSocket extended CONNECT and does not enable WebSocket compression. Host adapters continue to report only features that the selected Runwire integration can actually expose.

## Compatibility notes

The 2.0 work preserves unchanged public named arguments wherever possible. The important migration changes are behavioral contracts: terminal request ownership, stricter capability truth, bounded native fallback capacity, aggregate resource admission, process-tree cleanup, and more consistent response framing.

When a consumer depends on undocumented timing, host-product potential, duplicate GC, or post-handler cleanup timing, update that integration explicitly rather than attempting to recreate the 1.x behavior.

## Verification before rollout

For each real consumer:

- install production dependencies with --no-dev;
- run its full test suite on supported PHP versions;
- exercise persistent-state isolation and reset failure;
- verify the selected runtime capability snapshot;
- test cancellation and streaming completion;
- test reload/recycle and graceful shutdown where supported;
- run representative load with the same extensions, OPcache, worker count, protocol, TLS mode, and limits used in production;
- retain the benchmark and CI artifacts associated with the exact candidate commit.

See [deployment.md](deployment.md), [security.md](security.md), [coroutines.md](coroutines.md), and [benchmarks.md](benchmarks.md) for the 2.0 operational contracts.
