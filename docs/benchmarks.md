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

| Requirement | Why |
| --- | --- |
| 64-bit PHP 8.4/8.5 | Matches supported runtime lines. |
| Supported ext-quic build | Provides native QUIC transport. |
| Supported QUIC-capable OpenSSL baseline | Matches the native QUIC adapter requirements. |
| Certificate/private key | Required for HTTP/3 TLS. |
| Python + CI-pinned aioquic | Independent HTTP/3 client/load generator. |

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

The client emits structured measurement fields:

| Field | Meaning |
| --- | --- |
| `requests` | Measured request count. |
| `warmup_requests` | Requests excluded from the measured window. |
| `elapsed_ms` | Measured elapsed time. |
| `requests_per_second` | Measured throughput. |
| `amortized_us_per_request` | Average measured microseconds per request. |

Keep loopback results labeled as loopback results. They are useful for regressions and protocol/runtime tuning, not public Internet capacity claims.

## 4. Sustained real-server evidence

The benchmark workflow runs five repeated native HTTP/1.1 keep-alive trials against the real Runwire native server on PHP 8.4 and 8.5. Pull requests use short CI-smoke durations to validate correctness and evidence plumbing. They are not stable production baselines and do not enforce small timing deltas on shared runners.

The dedicated Release Certification workflow runs through `workflow_dispatch` or an explicit `release-certification` pull-request label. The label is the normal opt-in gate for a release-critical PR; ordinary PRs keep the lighter regression lanes.

| Certification job | Environment / duration | Pass condition |
| --- | --- | --- |
| Matched baseline/candidate performance | PHP 8.5; exact candidate vs pinned baseline; PCNTL/POSIX/ext-event/OPcache; 30 s warmup + five 180 s measured trials per side | Correctness passes, max RPS CV <2.5%, regression budget is enforced, candidate median RPS stays within 5% of baseline. |
| Candidate 30-minute HTTP soak | PHP 8.5; real native H1 server; concurrency 32; 30 s warmup + 1800 s measured traffic | Every request completes successfully; zero errors, timeouts, and validation failures. |
| Representative Infbyte consumer | Infbyte `main` with exact Runwire candidate | Full consumer suite passes; Foundation boots; health route and Webrick/Runwire bridge probe pass. |
| PHPForge release guard | PHP 8.5 with PCNTL/POSIX/ext-event/OPcache | Platform requirements pass and `composer ic:release:guard` succeeds. |

The resulting artifacts record:

| Evidence area | Recorded data |
| --- | --- |
| Correctness | Total/completed/successful requests, errors, timeouts, response-validation failures. |
| Latency | p50, p95, p99. |
| Throughput | Successful RPS and median successful RPM. |
| Stability | Trial-to-trial RPS coefficient of variation. |
| Resource use | Process-tree CPU and peak RSS. |
| Workload | Worker count, concurrency, connection reuse, duration. |
| Environment | PHP, extensions, OPcache, build, host OS, CPU metadata. |

A certification record fails if a response is incomplete, times out, errors, or fails response validation. The matched comparison additionally requires both five-trial RPS series to be stable enough for enforcement: the larger RPS coefficient of variation must be below 2.5%. When that condition is met, the candidate median successful RPS must remain within 5% of the baseline. The release workflow requires `budget_enforced == true` as well as `passed == true`, so evidence with CV at or above 2.5% is not accepted as a release pass; it must be rerun in a stable environment.

HTTP/3 real-server interoperability and soak evidence remains owned by the dedicated QUIC lane using aioquic and ngtcp2/nghttp3. Protocol-core PHPBench results remain separate from real-server throughput.

### ResponseTransfer and WebSocket acceptance smoke evidence

On exact-head commit `7b315220d7cd412ca0058f54802ce8b32800c95c`, the shared-runner ResponseTransfer acceptance lane remained inside its 5% helper budget:

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

When comparing one Runwire commit against another, keep these dimensions constant as far as practical:

| Dimension | Match requirement |
| --- | --- |
| Hardware / runner class | Same CPU/virtualization class. |
| PHP / extensions | Same PHP and extension versions. |
| Dependencies | Same Composer dependency set. |
| Protocol / TLS | Same protocol and TLS configuration. |
| Runtime shape | Same worker count and concurrency. |
| Workload | Same request/response behavior and payload shape. |
| Measurement | Same duration/warmup/trial structure. |
| Instrumentation | Same metrics/profiler/APM state. |

Treat a runner CPU model or virtualization change as an environment change before attributing a small timing difference to Runwire.

Use repeated evidence for performance decisions. Do not change safety limits because of one noisy sample.

## 7. Cross-runtime evidence schema

`benchmarks/comparative_evidence.php` validates independently collected records before rendering a comparison.

Comparable records must match these key dimensions:

| Group | Fields |
| --- | --- |
| Environment | `hardware_id`, `php_version`, extension set. |
| Protocol | `protocol`, `tls`, `connection_reuse`. |
| Workload | `workload`, `workers`, `concurrency`, `duration_seconds`. |
| Runtime settings | `instrumentation`, `opcache`. |

Each record should include:

| Evidence group | Fields |
| --- | --- |
| Runtime identity | `runtime`, `runtime_version`, `runtime_build`. |
| Environment | `host_os`, `host_cpu`, `hardware_id`, `extension_versions`, `instrumentation`, `tls`, `opcache`, `connection_reuse`. |
| Request accounting | `requests_total`, `completed_requests`, `successful_requests`. |
| Throughput/latency | `throughput_rps`, `latency_ms.p50`, `latency_ms.p95`, `latency_ms.p99`. |
| Correctness | `errors_total`, `timeouts_total`, `validation_failures`, `correctness_passed`, `error_rate`. |
| Resources | `cpu_percent`, `rss_peak_bytes`. |

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

| Workload class | What it isolates |
| --- | --- |
| Plaintext/minimal handler | Runtime/protocol overhead with minimal application work. |
| JSON response | Response serialization/write path. |
| JSON request + response | Request parsing/body + response path. |
| Streaming response | Backpressure and incremental output. |
| Streaming request | Incremental body/read pressure. |
| Multiplexed concurrency | H2/H3 scheduling/fairness under concurrent streams. |

Measure HTTP/1.1, HTTP/2, and HTTP/3 separately. If a peer cannot expose an equivalent protocol, mark that comparison inapplicable rather than substituting a different protocol.

## 10. Metrics to report

Throughput alone is insufficient.

Report together:

| Category | Metrics/context |
| --- | --- |
| Throughput | Successful throughput/RPS. |
| Latency | p50, p95, p99. |
| Correctness | Error count/rate, timeouts, validation failures. |
| Resources | CPU and peak RSS. |
| Runtime shape | Worker count and concurrency. |
| Measurement | Duration/warmup/trials. |
| Transport | Protocol and TLS state. |

For persistent runtimes, also observe:

| Persistent-runtime signal | Why |
| --- | --- |
| Memory growth over time | Detect leaks/unbounded retention. |
| File-descriptor stability | Detect resource leakage. |
| Event-loop backlog | Detect scheduler/IO saturation. |
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

| Area | Operation |
| --- | --- |
| Tasks | Spawn/join. |
| Scheduler | Ready-queue dispatch. |
| Futures | Resolve/await. |
| Channels | Rendezvous/buffering. |
| Synchronization | Mutex/semaphore contention. |
| Timers | Sleep/timer wakeup. |
| I/O | Stream readiness wakeup. |
| Request integration | Request-scope creation/drain. |

Always include policy settings such as `maxResumesPerTick` when comparing scheduler behavior.

Do not benchmark a blocking API inside a coroutine and then describe the result as asynchronous I/O throughput.

## 13. Instrumentation effects

Fixed-cardinality runtime metrics are part of normal runtime behavior. Optional diagnostics/profilers/APM can materially affect results.

Record an `instrumentation` label for comparative evidence:

| Label example | Meaning |
| --- | --- |
| `default` | Repository/default instrumentation state. |
| `metrics-only` | Runtime metrics enabled without external profiler/APM. |
| `xdebug-off-apm-off` | Explicitly uninstrumented application profile. |
| `production-apm` | Production-like APM/profiling stack enabled. |

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

H1, H2, and H3 default to FIXED by the final adaptive-policy release decision. AUTO/LATENCY/THROUGHPUT remain explicit opt-in policies. The adaptive CI jobs are short diagnostic comparisons; they record regressions but are not promotion evidence.

Run these commands in the same prepared PHP/native-extension environment as CI (including ext-event, and ext-quic for H3). The H2 script expects the pinned h2 client at `/tmp/runwire-h2/bin/python`; H3 uses `/tmp/runwire-aioquic/bin/python`, overridable with `RUNWIRE_AIOQUIC_PYTHON`. The workflow setup steps provision these clients.

```bash
# Existing quick checks: regression budgets remain enforced.
bash benchmarks/adaptive_http12_certify.sh 5 benchmark-results/adaptive-http12 diagnostic
bash benchmarks/adaptive_http3_certify.sh 5 benchmark-results/adaptive-http3 diagnostic

# Strict, repeated comparisons for the scripts' reported workloads.
bash benchmarks/adaptive_http12_certify.sh 180 benchmark-results/adaptive-http12-sustained sustained
bash benchmarks/adaptive_http3_certify.sh 180 benchmark-results/adaptive-http3-sustained sustained
```

The filenames are retained for compatibility. Each script runs five alternating AUTO/FIXED trials. `sustained` requests 30 seconds of warm-up and at least 180 seconds for each steady-state phase. Transition phases remain short to measure transitions. Strict comparison rejects RPS CV at or above 2.5% and keeps the existing throughput, latency, CPU, RSS and fairness budgets. Diagnostic mode does not relax comparison thresholds: regression failures remain recorded in the JSON result together with duration/variance deficiencies. Because AUTO is no longer a production default, diagnostic AUTO regressions are advisory and do not fail ordinary CI. `sustained` mode remains strict and returns nonzero when a promotion gate fails.

Records include payload/workload, phase concurrency and requested/actual duration, runtime build and environment identity. Summaries reject mismatched or invalid trials and report per-phase sample CV plus successful RPM. Comparisons require matching metadata. Keep the raw trials, summaries, comparison JSON, and environment details together.

These scripts currently cover H1 plaintext 2-byte responses, H2 TLS 768-byte responses, and H3 16 KiB responses. Even a passing strict comparison is a **single-workload** result and always reports `promotion_certified: false`. Use the expanded promotion runner below for mixed-payload, constrained-flow/pressure, QPACK, churn, profile and concurrency coverage. Missing matrix cells remain pending; a short diagnostic result cannot certify them.

### Complete adaptive promotion matrix

`adaptive_promotion_matrix.php` defines 86 mandatory cases: 16 H1 (plaintext/TLS, four sizes, short/long reuse), 28 H2 (four sizes, mixed payloads, constrained flow and transport reads, 1/8/32/100 streams), and 42 H3 (four sizes, three concurrency levels, upload/download/balanced traffic, delayed QPACK instructions and connection churn). Each case runs FIXED, LATENCY, THROUGHPUT and AUTO, five trials each with rotated order, 30-second warm-up, and 180-second low/medium/high/low steady phases surrounding short up/down transitions.

Run on a dedicated Linux host with the native extensions and OpenSSL CLI. The complete matrix orchestration/evaluation, case manifest and gate contracts are PHP-native. HTTP/1.1 retains its asynchronous Python stdlib load client because the short-reuse TLS cells require concurrent connection/handshake churn; replacing it with the synchronous PHP sustained client would weaken generator fidelity. HTTP/2 deliberately retains the pinned independent `h2==4.3.0` client, and HTTP/3 retains the pinned independent `aioquic==1.3.0` client. Set `RUNWIRE_H1_PYTHON`, `RUNWIRE_H2_PYTHON`, and `RUNWIRE_AIOQUIC_PYTHON` if those client environments are outside the defaults. Client dependencies stay outside production Composer dependencies.

```bash
php benchmarks/adaptive_promotion_matrix.php list --protocol h2
php benchmarks/adaptive_promotion_matrix.php run --protocol h2 --output benchmark-results/adaptive-promotion-h2
php benchmarks/adaptive_promotion_matrix.php run --protocol h3 --output benchmark-results/adaptive-promotion-h3
php benchmarks/adaptive_promotion_matrix.php run --protocol http1 --output benchmark-results/adaptive-promotion-h1
# Resume an interrupted run with the identical candidate and environment:
php benchmarks/adaptive_promotion_matrix.php run --protocol h2 --output benchmark-results/adaptive-promotion-h2 --resume
# Recompute summaries and the gate from raw records, including missing cases:
php benchmarks/adaptive_promotion_matrix.php evaluate --protocol h2 --output benchmark-results/adaptive-promotion-h2
```

Use repeated `--case <id>` arguments to run selected cells, then `--resume` to fill the rest. Partial selections never pass the full protocol gate. For a quick harness check, use a separate output directory with `--case <id> --diagnostic --seconds 1 --warmup 1`; short runs also return nonzero because they cannot certify the matrix. A full run is intentionally lengthy: approximately 4.2 hours per cell, or 67/117/176 hours for H1/H2/H3 before connection/drain overhead. Do not run competing load jobs on the same host. No native sustained matrix was run during harness implementation.

The evaluator checks every mandatory case and all four modes from raw trials, matching code/environment/workload identities and distinct run IDs. It reuses the existing regression budgets, strict duration requirements and CV ceiling. Additional preregistered criteria are a 5% steady throughput gain over FIXED in at least one case that also exceeds twice the combined AUTO/FIXED CV, transition overhead no greater than 1% throughput and 0.1 ms p95/p99, and generator CPU below 85% of one core in every trial. These are acceptance criteria, not measured improvements. A generator-bound run needs more capable load generation and fresh evidence; do not interpret it as a server limit.

The clients validate status and response bytes, validate uploaded bytes end to end, use bounded latency histograms, and rotate drained connections below unchanged production stream/keep-alive limits. H2/H3 retain live connection state across phase transitions except the deliberate churn case and required lifetime rotation. The H3 backlog case delays actual QPACK encoder instructions and requires observed dynamic header dependencies in every phase; the [encoder hook](https://github.com/aiortc/aioquic/blob/1.3.0/src/aioquic/h3/connection.py) is pinned to aioquic 1.3.0. Transport-pressure cases record delayed reads and flow-control cases record bytes received under the reduced stream window rather than merely naming a workload “pressure.” Server/client logs, failure markers and raw records are retained. Failed trials are not silently retried or replaced by passing samples.

`<protocol>-promotion.json` reports `performance_gate_passed` independently per protocol. It never edits defaults and always keeps `promotion_certified: false`: exact-candidate protocol/security/resource-invariant CI and release certification must also pass. The 2.0 release decision keeps H1/H2/H3 FIXED; a future AUTO-default proposal must explicitly reopen the promotion decision.

Harness regression tests:

```bash
# PHP-owned manifest, evaluator, provenance, and promotion-gate contracts.
vendor/bin/pest tests/AdaptiveBenchmarkEvidenceTest.php tests/AdaptivePromotionMatrixTest.php

# Asynchronous HTTP/1.1 load client.
RUNWIRE_H1_PYTHON=/tmp/runwire-h2/bin/python \
  /tmp/runwire-h2/bin/python -m unittest discover -s tests/benchmarks -p 'test_adaptive_h1_client.py' -v

# Independent HTTP/2 transport client.
RUNWIRE_H2_PYTHON=/tmp/runwire-h2/bin/python \
  /tmp/runwire-h2/bin/python -m unittest discover -s tests/benchmarks -p 'test_adaptive_h2_client.py' -v

# Independent HTTP/3/aioquic transport client.
RUNWIRE_AIOQUIC_PYTHON=/tmp/runwire-aioquic/bin/python \
  /tmp/runwire-aioquic/bin/python -m unittest discover -s tests/benchmarks -p 'test_adaptive_http3_client.py' -v
```

Python is intentionally retained only for the asynchronous H1 load client and the independent hyper-h2/aioquic load or interoperability clients. The promotion runner, case manifest/evaluation, port allocation, and promotion-gate contract tests are PHP/Bash-owned.

## Related documentation

- [2.0 migration guide](migration-2.0.md)

- [Getting started](getting-started.md)
- [Architecture and runtime contracts](architecture.md)
- [Deployment and operations](deployment.md)
- [Coroutines and structured concurrency](coroutines.md)
