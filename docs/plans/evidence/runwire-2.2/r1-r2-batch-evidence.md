# Runwire 2.2 — R1 completion and R2 fairness work


### R1 functional QA → R2 timer fairness implementation (2026-10-20)

**R1 evidence:** The preceding code commit `e4573a4e7924ed62c14fae51f7f3728d9d1d90ab` completed PHPForge Pest and analysis on PHP8.4/8.5 stable/lowest dependencies, Swoole/OpenSwoole and portable-native lanes; long unrelated QUIC/diagnostics jobs were still running at the time of this transition. Task R1.02 is the real code simplification of CGI HTTP header normalization, matching existing scalars and validation. R1.03 is a **documented no-change optimization decision**: RequestFinalizer already provides exactly-once response terminal/cleanup and exception aggregation, so riskier finalizer rewrites were rejected. R1.04 exception aggregation and R1.05 identity/single-activation contracts are verified in `ApplicationLifecycleTest`, `RequestContextActivationTest`, `PersistentRuntimeIsolationTest`, and the new `R1RequestLifecycleRegressionTest`. R1.06 cold/warmed hosted costs and OPcache footprint are measured in current R0 host profiling and existing PHPBench evidence. **R1.08 remains open**: final native/host comparable A/B with tracing cannot be certified from per-operation or cross-runner data.

**R2.02 implementation:** `SelectLoop::runDueTimers` previously drained all currently due callbacks in one tick, potentially preventing socket watchers from receiving an event-loop turn. A conservative 256-due-entry limit per tick ensures the reactor gets a chance to poll I/O during a 600-timer burst. A cancelled/stale timer still counts toward the per-tick work budget. Outstanding due timers remain in the heap and are delivered on later ticks. New `SelectLoopTest` cases assert exact work counts 256/512/600 and ready-socket progress at 256. Ext-event backend unchanged. This is a **fairness/correctness fix**, not an assumed successful RPM gain; focused/full CI must pass before R2.02 is considered QA certified. 

**QA links:** [R1 PHPForge](https://github.com/infocyph/Runwire/actions/runs/39372118013), [R1 benchmarks](https://github.com/infocyph/Runwire/actions/runs/39372117689), [Swoole](https://github.com/infocyph/Runwire/actions/runs/39372117726), [Portable](https://github.com/infocyph/Runwire/actions/runs/39372117688).

**Definition:** R1.08 remains a hard performance gate; no unqualified throughput improvement claimed. R2 timer limits require fresh PHP8.4/8.5 and portable event-loop QA, including exact-SHA failure review. Neither changes Rust library behavior.

### R2 scheduling/cancellation audit QA and R3 byte-queue experiment (2026-10-20)

- R2.03: existing `MonotonicTimeTest`, `SelectLoopTest`, and `BatchLCoroutineSchedulerTest` validate ordering, monotonic deadlines, cancellation-before/after readiness and timer cancellation. R2.04: existing bounded ready queue, `TimerQueue` heap compaction and cancellation cleanup reviewed; no extra caching introduced.
- R2.05: `BatchMStructuredConcurrencyTest` verifies fail-fast/collect-all, nested deadline, task-local inherited snapshot and request-scope reuse. R2.06: `BatchNSynchronizationTest` verifies zero-capacity rendezvous FIFO, bounded waiters, semaphore ownership, mutex waiters, barrier cancellation/reuse. R2.07: [native portable 39372948300](https://github.com/infocyph/Runwire/actions/runs/39372948300) passed PHP8.4/8.5 and scalable ext-event; `EventLoopTest` covers >1024 descriptors, core SelectLoop remains 256 owned-connection limit. R2.08: `AsyncConnectionTest` and `BatchLCoroutineSchedulerTest` exercise I/O watcher deregistration, EOF, cancellation, pressure and close. Those source suites passed at prior green baseline; new bounded timer tests passed Pest on SHA `429d956e`. Pint initially failed constant ordering and was fixed on `5e14b750`; its full new CI still requires final confirmation. **R2.01** scheduler all-load allocation profiler and **R2.09** sustained mixed p99/queue soak remain open (R8 qualification).
- R3.02 experiment: `ByteQueue::read` had an `O(chunks)` loop that called `discard()` for *each* chunk, reapplying compaction checks and budget releases. The candidate performs exactly one aggregate budget release and compaction after reading, retaining chunk order, partial offsets, byte accounting and validation. Local PHP8.4.24 isolated emulation consumed 525k bytes over 350 repetitions: old 42.5ms vs candidate 31.3ms (same machine); this is **not** full-server RPS evidence. New `ByteQueueReadTest` covers partial reads, 300-chunk compaction, exact shared quota, interleaved discard and queue reuse. R3.02 **requires fresh CI** before being considered complete.

## R2.01 scheduler pressure task complete — 2026-10-20

[Benchmark workflow 39378520084](https://github.com/infocyph/Runwire/actions/runs/39378520084) ran `benchmarks/r2_scheduler_pressure.php` on PHP 8.4.26 and PHP 8.5.11 with 6 measured rounds after 2 warmups for each of 8/32/128 tasks, covering spawn/join, two yields, timer sleep/wake, cancelled sleeping tasks and actual Unix socket readiness. It also verified fail-closed rejection at `maxTasks=8` and `maxReadyBacklog=8`, with zero retained tasks.

| 128-task isolated operation | PHP 8.4 median µs | PHP 8.5 median µs | Max ready depth |
|---|---:|---:|---:|
| Spawn/join | 1859.727 | 1812.716 | 128 |
| Yield/resume twice | 2593.540 | 2531.721 | 128 |
| Sleep/wake | 3127.273 | 2972.876 | 128 |
| Cancel sleeping tasks | 3438.642 | 3201.402 | 129 |
| Unix socket readiness (2 tasks) | 80.641 | 72.990 | 2 |

Each runner reported 12,582,912 PHP peak allocation bytes, correct completion counts and zero lingering timers/watchers/active tasks. Overload rejection at the configured eight-task limit left zero active tasks and peaked at ready depth 7. The PHP versions used distinct runners; the results are **not** a PHP version comparison, not real network saturation and not a 2.1.2→2.2 end-to-end throughput claim. R2.09's sustained mixed-load/fairness soak remains outstanding.


### R2.09 sustained mixed-scheduler pressure QA — accepted (2026-10-20)

**Exact source:** [93416ea](https://github.com/infocyph/Runwire/commit/93416eaabd367146e565d3e0752ed7cf1f992a37).
[PHP 8.4 and PHP 8.5 benchmark jobs 39441738432](https://github.com/infocyph/Runwire/actions/runs/39441738432) both passed the same candidate source for three 3-second load tiers with real Unix socket pair I/O, 4ms repeat timers, and CPU burst levels of 8, 32 and 96 callbacks. Each tier wrote 750 and read 749 validated bytes (one last write pending at controlled stop), held at most one queued byte, and cleaned all remaining timers/readable watchers/deferred callbacks. Peak *sampled PHP allocation drift* was zero in these bounded runs; this does not prove no long-run native process leak.

| PHP | CPU callbacks per tick | p99 readable progress | Max pending bytes | Leaked timers/watchers |
|---|---|---|---|---|
| 8.4.26 | 8 / 32 / 96 | 0.081 / 0.140 / 0.393 ms | 1 | 0 |
| 8.5.11 | 8 / 32 / 96 | 0.075 / 0.178 / 0.378 ms | 1 | 0 |

The first [benchmark run 39441425725](https://github.com/infocyph/Runwire/actions/runs/39441425725) failed because the harness itself left the *last* burst's one-shot CPU timer registrations pending when its timeout fired; [fix 93416ea](https://github.com/infocyph/Runwire/commit/93416eaabd367146e565d3e0752ed7cf1f992a37) explicitly cancels those handles and emits per-field accounting on failure. This remains a fail-closed test, not a waived assertion. [Security & Standards run 39441739912](https://github.com/infocyph/Runwire/actions/runs/39441739912) passed all four PHPForge Pest/format QA variants (8.4/8.5 stable/lowest) at the exact source; optional QUIC/analysis jobs were not all finished when this task was closed.

**R2.09 completed** as a scheduler-local sustained fairness/p99/queue proof; comparison versus published 2.1.2, traffic at production protocol/network saturation and long-run process RSS drift remain independent R0/R3/R4/R7/R8 gates. Do not infer a successful-RPM speedup from this short 3×3 second micro/mixed benchmark.
