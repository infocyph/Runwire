# Runwire 2.0 Benchmark Methodology

Runwire benchmarks are regression evidence and workload-specific measurements. They are not universal capacity claims and must not be presented as proof that Runwire is the fastest PHP runtime or framework.

## 1. Evidence layers

Runwire deliberately separates different cost layers.

### Protocol-core microbenchmarks

`benchmarks/ProtocolCoreBench.php` measures deterministic in-process protocol work such as:

- HTTP/1.1 request-head validation;
- HTTP/2 HPACK encode/decode;
- HTTP/3 frame encode/decode;
- QPACK static operations;
- QPACK dynamic-table round trips and encoder/decoder instruction exchange.

These exclude socket, TLS, kernel, QUIC handshake, host-runtime, and application costs.

### Lifecycle/runtime microbenchmarks

`benchmarks/LifecycleRuntimeBench.php` measures bounded runtime overhead such as:

- request-context creation/completion;
- request-local attribute handling;
- request resetter dispatch;
- metrics accounting/snapshot creation;
- worker recycle accounting at safe boundaries.

### Host-adapter microbenchmarks

`benchmarks/HostAdapterBench.php` measures Runwire-owned host adaptation such as:

- host request normalization;
- `RuntimeApplication` dispatch;
- cleanup/reset boundaries;
- response-writer lifecycle.

A host-adapter microbenchmark is **not** FrankenPHP/RoadRunner/Swoole server throughput. Real host throughput must run the real host.

### End-to-end native HTTP/3

Dedicated QUIC CI exercises a real HTTP/3 client against the production native adapter and validates:

- protocol behavior;
- response correctness;
- interoperability;
- sustained request handling;
- graceful drain/GOAWAY behavior.

This includes native transport and therefore must not be compared directly with in-process PHPBench subjects.

## 2. Run PHPBench locally

Install development dependencies:

```bash
composer install
```

Run the repository benchmark suite:

```bash
vendor/bin/phpbench run \
  --report=aggregate \
  --output=json \
  --progress=none \
  --bootstrap=vendor/autoload.php \
  --config=vendor/infocyph/phpforge/resources/phpbench.json \
  benchmarks
```

PHPForge benchmark commands may also be used where available:

```bash
composer ic:benchmark
composer ic:bench:run
composer ic:bench:quick
```

Use the dedicated repository workflow as the release evidence because it records the exact tested commit and environment metadata.

## 3. Native HTTP/3 loopback benchmark

Requirements:

```text
64-bit PHP 8.4/8.5
supported ext-quic build
supported QUIC-capable OpenSSL baseline
certificate/private key
Python + aioquic version used by CI
```

Representative loopback run:

```bash
php benchmarks/http3_server.php \
  8443 \
  /path/to/cert.pem \
  /path/to/key.pem \
  /tmp/runwire-h3-ready \
  288 &

python benchmarks/aioquic_http3_bench.py 8443 256 32
```

The final two numbers represent measured requests and warmup requests for this example.

The client emits structured measurement fields such as:

```text
requests
warmup_requests
elapsed_ms
requests_per_second
amortized_us_per_request
```

Keep loopback results labeled as loopback results. They are useful for regressions and protocol/runtime tuning, not public Internet capacity claims.

## 4. Sustained real-server evidence

The benchmark workflow runs five repeated native HTTP/1.1 keep-alive trials against the real Runwire native server on PHP 8.4 and 8.5. Pull requests use short CI-smoke durations to validate correctness and evidence plumbing. They are not stable production baselines and do not enforce small timing deltas on shared runners.

The same workflow exposes a manual release-certification mode. It uses the phase-4 starting settings of a 30-second warmup, five 180-second measured trials, then a 30-minute sustained soak. The resulting artifacts record:

- total, completed and successful requests;
- errors, timeouts and response-validation failures;
- p50/p95/p99 latency;
- successful RPS and median successful RPM across repeated trials;
- trial-to-trial RPS coefficient of variation;
- process-tree CPU and peak RSS;
- worker count, concurrency, connection reuse and duration;
- PHP, extension, OPcache, build, host OS and CPU metadata.

A certification record fails if a response is incomplete, times out, errors, or fails response validation. Timing variance is recorded rather than hidden; no 5% regression threshold is enforced until stable-environment variance proves such a threshold meaningful.

HTTP/3 real-server interoperability and soak evidence remains owned by the dedicated QUIC lane using aioquic and ngtcp2/nghttp3. Protocol-core PHPBench results remain separate from real-server throughput.

## 5. Release CI evidence

The final Runwire 2.0 release candidate should have exact-head evidence for:

- benchmark workflow on supported PHP versions;
- PHPForge QA/analysis lanes;
- clean production install;
- Swoole/OpenSwoole focused acceptance;
- native QUIC/HTTP/3 protocol tests;
- aioquic interoperability;
- ngtcp2/nghttp3 interoperability where supported;
- native HTTP/3 soak/drain acceptance.

A later source/runtime change invalidates earlier exact-head certification.

Documentation-only changes may still trigger CI according to repository policy; the release decision should reference the final commit that is actually merged/tagged.

## 6. Regression comparison rules

When comparing one Runwire commit against another, keep these constant as far as practical:

```text
hardware / runner class
PHP version
extension versions
Composer dependency set
protocol
TLS settings
worker count
concurrency
workload
measurement duration
instrumentation
```

Treat a runner CPU model or virtualization change as an environment change before attributing a small timing difference to Runwire.

Use repeated evidence for performance decisions. Do not change safety limits because of one noisy sample.

## 7. Cross-runtime evidence schema

`benchmarks/comparative_evidence.php` validates independently collected records before rendering a comparison.

Comparable records must match key environment/workload dimensions:

```text
hardware_id
php_version
protocol
workload
workers
concurrency
duration_seconds
```

Each record should include:

```text
runtime
runtime_version
runtime_build
instrumentation
host_os / host_cpu / hardware_id
tls / opcache / connection_reuse
extension_versions
requests_total
completed_requests
successful_requests
throughput_rps
latency_ms.p50
latency_ms.p95
latency_ms.p99
errors_total
timeouts_total
validation_failures
correctness_passed
error_rate
cpu_percent
rss_peak_bytes
```

Schema example:

```json
{
  "runtime": "runwire-native",
  "runtime_version": "1.0",
  "protocol": "http/1.1",
  "workload": "plaintext-minimal",
  "hardware_id": "bench-host-01",
  "php_version": "8.4.x",
  "instrumentation": "default",
  "workers": 4,
  "concurrency": 128,
  "duration_seconds": 60,
  "throughput_rps": 0,
  "latency_ms": {
    "p50": 0,
    "p95": 0,
    "p99": 0
  },
  "errors_total": 0,
  "error_rate": 0,
  "cpu_percent": 0,
  "rss_peak_bytes": 0
}
```

The zero values are **schema placeholders only**, not benchmark results.

Validate/render completed real records:

```bash
php benchmarks/comparative_evidence.php \
  runwire.json \
  workerman.json \
  openswoole.json
```

## 8. Valid peer comparisons

Reasonable peer candidates include, where equivalent deployment/protocol is possible:

- Workerman;
- Swoole/OpenSwoole;
- FrankenPHP worker mode;
- RoadRunner;
- applicable Octane-backed runtimes.

Rules:

1. run the real peer runtime;
2. use the same physical/virtual host;
3. use the same PHP version/configuration where applicable;
4. use the same protocol/TLS configuration;
5. use the same worker count and concurrency;
6. use the same application behavior;
7. collect the same duration and telemetry.

Do not substitute a Runwire host-adapter microbenchmark for running the real host engine.

## 9. Workload classes

Do not collapse unrelated workload shapes into one ranking.

At minimum separate:

```text
plaintext/minimal handler
JSON response
JSON request + response
streaming response
streaming request
multiplexed protocol concurrency where supported
```

Measure HTTP/1.1, HTTP/2, and HTTP/3 separately. If a peer cannot expose an equivalent protocol, mark that comparison inapplicable rather than substituting a different protocol.

## 10. Metrics to report

Throughput alone is insufficient.

Report together:

```text
throughput
p50 latency
p95 latency
p99 latency
error count/rate
CPU
peak RSS
worker count
concurrency
duration
protocol
TLS state
```

For persistent runtimes, also observe:

- memory growth over time;
- file-descriptor stability;
- event-loop backlog;
- worker recycle/reload impact;
- error recovery after overload.

## 11. Reload/recycle measurement

Operational lifecycle measurements must run real supervised workers.

### Rolling reload

Record:

- steady-state throughput before reload;
- minimum successful capacity during replacement;
- replacement readiness time;
- old-generation drain duration;
- errors/latency during the window;
- `maxSurge` and `maxUnavailable`;
- readiness/drain timeouts.

A zero-unavailable policy should demonstrate replacement readiness before healthy old capacity is retired.

### Worker recycle

Record:

- trigger reason;
- effective jittered threshold;
- latency/throughput before, during, after replacement;
- active-request drain behavior;
- restart/reap outcome;
- memory before/after replacement.

Planned recycle exits must not be counted as crashes.

## 12. Coroutine benchmarking

Coroutine microbenchmarks should isolate:

```text
task spawn/join
ready-queue dispatch
future resolve/await
channel rendezvous/buffering
mutex/semaphore contention
sleep/timer wakeup
stream readiness wakeup
request-scope creation/drain
```

Always include policy settings such as `maxResumesPerTick` when comparing scheduler behavior.

Do not benchmark a blocking API inside a coroutine and then describe the result as asynchronous I/O throughput.

## 13. Instrumentation effects

Fixed-cardinality runtime metrics are part of normal runtime behavior. Optional diagnostics/profilers/APM can materially affect results.

Record an `instrumentation` label for comparative evidence, for example:

```text
default
metrics-only
xdebug-off-apm-off
production-apm
```

Never compare an instrumented peer with an uninstrumented Runwire run without stating the difference.

## 14. Benchmark integrity rules

1. Do not add benchmark-only production branches that bypass normal validation or safety behavior.
2. Do not disable limits to inflate a benchmark without reporting the changed limit.
3. Do not compare microbenchmark operations with end-to-end requests/second.
4. Keep QUIC/TLS transport cost separate from QPACK/frame microbenchmarks.
5. Keep host-adapter cost separate from host-engine networking cost.
6. Record environment metadata with every durable result.
7. Investigate sustained regressions with profiling before changing architecture.
8. Report latency/errors/CPU/RSS with throughput.
9. Do not rank by RPS alone.
10. Do not describe Runwire-only CI evidence as a Foundation/Webrick full-stack result.
11. Do not publish synthetic validator fixtures as measurements.
12. Do not invent missing peer results.

## 15. Release-claim boundary

Runwire 2.0 may be released without a public cross-runtime ranking.

A public statement such as “fastest”, “faster than X”, or “top-tier” requires equivalent real peer evidence. Until that evidence exists, benchmark artifacts should be described as regression, protocol, interoperability, or workload-specific measurements.

## Related documentation

- [Getting started](getting-started.md)
- [Architecture and runtime contracts](architecture.md)
- [Deployment and operations](deployment.md)
- [Coroutines and structured concurrency](coroutines.md)
- [Runwire 1.0 launch plan](plans/runwire-1.0-foundation-3-launch-plan.md)
