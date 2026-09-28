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

`benchmarks/AdaptiveProtocolBench.php` separately measures the bounded strategy-selection overhead for HTTP/1.1, HTTP/2, and HTTP/3 across AUTO low/high load and deterministic FIXED profiles. These subjects are controller/scheduler microbenchmarks, not end-to-end throughput claims. They exist so adaptive-policy bookkeeping remains continuously visible in the normal PHPBench regression suite.

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

The same workflow exposes release-certification mode through `workflow_dispatch`, the historical `feature/next-edition` lane, or an explicit `release-certification` pull-request label. It uses the phase-4 starting settings of a 30-second warmup, five 180-second measured trials, then a 30-minute sustained soak. The label is an opt-in gate for release-critical PRs; ordinary PRs keep the lighter regression lanes. The resulting artifacts record:

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

### F-04/F-05 acceptance smoke evidence

On exact-head commit `7b315220d7cd412ca0058f54802ce8b32800c95c`, the shared-runner acceptance lane remained inside the 5% F-04 helper budget:

| PHP | ResponseTransfer helper | Manual bounded pump | Delta | Helper CV | Manual CV |
| --- | ---: | ---: | ---: | ---: | ---: |
| 8.4 | 5191.538 MiB/s | 5291.444 MiB/s | -1.888% | 2.618% | 3.498% |
| 8.5 | 1660.119 MiB/s | 1673.261 MiB/s | -0.785% | 0.298% | 0.299% |

The same exact-head run exercised the PHP-stdlib RFC 6455 client without importing Runwire WebSocket classes:

| PHP | Messages | Median msg/s | Rate CV | Median p95 | Median p99 | Slow reader |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| 8.4 | 451,007 | 43,949.085 | 1.050% | 0.202 ms | 0.219 ms | pass |
| 8.5 | 182,076 | 17,640.028 | 0.844% | 0.564 ms | 0.589 ms | pass |

These are CI-smoke measurements for acceptance/regression evidence, not production-capacity claims. The manual `workflow_dispatch` certification mode remains the pre-tag owner of the 180-second repeated trials and 30-minute soak.

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
instrumentation
workers
concurrency
duration_seconds
tls
opcache
connection_reuse
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
  "runtime_version": "2.0-candidate",
  "runtime_build": "<candidate-commit>",
  "protocol": "http/1.1",
  "workload": "plaintext-keepalive",
  "hardware_id": "bench-host-01",
  "host_os": "Linux ...",
  "host_cpu": "CPU model ...",
  "php_version": "8.4.x",
  "instrumentation": "release-certification",
  "tls": "off",
  "opcache": "enabled-cli",
  "connection_reuse": "keep-alive",
  "extension_versions": {
    "event": "3.x",
    "Zend OPcache": "8.4.x"
  },
  "workers": 1,
  "concurrency": 128,
  "duration_seconds": 180,
  "requests_total": 0,
  "completed_requests": 0,
  "successful_requests": 0,
  "throughput_rps": 0,
  "latency_ms": {
    "p50": 0,
    "p95": 0,
    "p99": 0
  },
  "errors_total": 0,
  "timeouts_total": 0,
  "validation_failures": 0,
  "correctness_passed": false,
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

## Adaptive policy evidence

H1 defaults to FIXED with NODELAY on; H2/H3 default to AUTO by the release decision. The complete J10 matrix still requires external measurement; these defaults do not imply that certification has passed. The adaptive CI jobs are short diagnostic comparisons; their green status is not promotion evidence.

Run these commands in the same prepared PHP/native-extension environment as CI (including ext-event, and ext-quic for H3). The H2 script expects the pinned h2 client at `/tmp/runwire-h2/bin/python`; H3 uses `/tmp/runwire-aioquic/bin/python`, overridable with `RUNWIRE_AIOQUIC_PYTHON`. The workflow setup steps provision these clients.

```bash
# Existing quick checks: regression budgets remain enforced.
bash benchmarks/adaptive_http12_certify.sh 5 benchmark-results/adaptive-http12 diagnostic
bash benchmarks/adaptive_http3_certify.sh 5 benchmark-results/adaptive-http3 diagnostic

# Strict, repeated comparisons for the scripts' reported workloads.
bash benchmarks/adaptive_http12_certify.sh 180 benchmark-results/adaptive-http12-sustained sustained
bash benchmarks/adaptive_http3_certify.sh 180 benchmark-results/adaptive-http3-sustained sustained
```

The filenames are retained for compatibility. Each script runs five alternating AUTO/FIXED trials. `sustained` requests 30 seconds of warm-up and at least 180 seconds for each steady-state phase. Transition phases remain short to measure transitions. Strict comparison rejects RPS CV at or above 2.5% and keeps the existing throughput, latency, CPU, RSS and fairness budgets. H1 AUTO remains advisory as before; its regression failures are recorded without failing the combined H1/H2 job. H2/H3 regression budgets remain blocking even in diagnostic mode. Diagnostic mode does not relax their thresholds; it separately reports duration/variance deficiencies through `evidence_failures` and `measurement_qualified`.

Records include payload/workload, phase concurrency and requested/actual duration, runtime build and environment identity. Summaries reject mismatched or invalid trials and report per-phase sample CV plus successful RPM. Comparisons require matching metadata. Keep the raw trials, summaries, comparison JSON, and environment details together.

These scripts currently cover H1 plaintext 2-byte responses, H2 TLS 768-byte responses, and H3 16 KiB responses. Even a passing strict comparison is a **single-workload** result and always reports `promotion_certified: false`. Use the expanded J10 runner below for mixed-payload, constrained-flow/pressure, QPACK, churn, profile and concurrency coverage. Missing matrix cells remain pending; a short diagnostic result cannot certify them.

### Complete J10 matrix

`adaptive_promotion_matrix.py` defines 86 mandatory cases: 16 H1 (plaintext/TLS, four sizes, short/long reuse), 28 H2 (four sizes, mixed payloads, constrained flow and transport reads, 1/8/32/100 streams), and 42 H3 (four sizes, three concurrency levels, upload/download/balanced traffic, delayed QPACK instructions and connection churn). Each case runs FIXED, LATENCY, THROUGHPUT and AUTO, five trials each with rotated order, 30-second warm-up, and 180-second low/medium/high/low steady phases surrounding short up/down transitions.

Run on a dedicated Linux host with the native extensions, Python 3.11+, OpenSSL CLI, `h2==4.3.0` and `aioquic==1.3.0`. Set `RUNWIRE_H2_PYTHON` and `RUNWIRE_AIOQUIC_PYTHON` if the clients are outside the paths above. Client dependencies stay outside production Composer dependencies.

```bash
python3 benchmarks/adaptive_promotion_matrix.py list --protocol h2
python3 benchmarks/adaptive_promotion_matrix.py run --protocol h2 --output benchmark-results/j10-h2
python3 benchmarks/adaptive_promotion_matrix.py run --protocol h3 --output benchmark-results/j10-h3
python3 benchmarks/adaptive_promotion_matrix.py run --protocol http1 --output benchmark-results/j10-h1
# Resume an interrupted run with the identical candidate and environment:
python3 benchmarks/adaptive_promotion_matrix.py run --protocol h2 --output benchmark-results/j10-h2 --resume
# Recompute summaries and the gate from raw records, including missing cases:
python3 benchmarks/adaptive_promotion_matrix.py evaluate --protocol h2 --output benchmark-results/j10-h2
```

Use repeated `--case <id>` arguments to run selected cells, then `--resume` to fill the rest. Partial selections never pass the full protocol gate. For a quick harness check, use a separate output directory with `--case <id> --diagnostic --seconds 1 --warmup 1`; short runs also return nonzero because they cannot certify the matrix. A full run is intentionally lengthy: approximately 4.2 hours per cell, or 67/117/176 hours for H1/H2/H3 before connection/drain overhead. Do not run competing load jobs on the same host. No native sustained matrix was run during harness implementation.

The evaluator checks every mandatory case and all four modes from raw trials, matching code/environment/workload identities and distinct run IDs. It reuses the existing regression budgets, strict duration requirements and CV ceiling. Additional preregistered criteria are a 5% steady throughput gain over FIXED in at least one case that also exceeds twice the combined AUTO/FIXED CV, transition overhead no greater than 1% throughput and 0.1 ms p95/p99, and generator CPU below 85% of one core in every trial. These are acceptance criteria, not measured improvements. A generator-bound run needs more capable load generation and fresh evidence; do not interpret it as a server limit.

The clients validate status and response bytes, validate uploaded bytes end to end, use bounded latency histograms, and rotate drained connections below unchanged production stream/keep-alive limits. H2/H3 retain live connection state across phase transitions except the deliberate churn case and required lifetime rotation. The H3 backlog case delays actual QPACK encoder instructions and requires observed dynamic header dependencies in every phase; the [encoder hook](https://github.com/aiortc/aioquic/blob/1.3.0/src/aioquic/h3/connection.py) is pinned to aioquic 1.3.0. Transport-pressure cases record delayed reads and flow-control cases record bytes received under the reduced stream window rather than merely naming a workload “pressure.” Server/client logs, failure markers and raw records are retained. Failed trials are not silently retried or replaced by passing samples.

`<protocol>-promotion.json` reports `performance_gate_passed` independently per protocol. It never edits defaults and always keeps `promotion_certified: false`: exact-candidate protocol/security/resource-invariant CI and I8 release certification must also pass. H1 remains FIXED and H2/H3 remain AUTO unless a separate release decision changes them.

Harness regression tests:

```bash
# Use a Python environment with both pinned protocol-client dependencies.
python3 -m unittest discover -s tests/benchmarks -p 'test_adaptive*.py' -v
```

## Related documentation

- [2.0 migration guide](migration-2.0.md)

- [Getting started](getting-started.md)
- [Architecture and runtime contracts](architecture.md)
- [Deployment and operations](deployment.md)
- [Coroutines and structured concurrency](coroutines.md)
