# Runwire 1.0 Benchmark Methodology

Runwire benchmarks are release evidence and regression signals. They are not universal capacity claims: GitHub-hosted runners, local developer machines and production hosts have different CPU, kernel, crypto and virtualization characteristics.

The benchmark suite deliberately keeps protocol-core cost, lifecycle/runtime cost, host-adapter cost and native transport cost separate. Combining them into one headline number would hide where time is actually spent.

## Benchmark layers

### Protocol core

`benchmarks/ProtocolCoreBench.php` records deterministic PHPBench subjects for:

- HTTP/1.1 request-head validation;
- HTTP/2 HPACK encode/decode;
- HTTP/3 frame encode/decode;
- HTTP/3 QPACK static encode/decode;
- HTTP/3 QPACK dynamic-table round trip including encoder/decoder instruction exchange.

These are in-process protocol measurements. They intentionally exclude sockets, TLS, QUIC handshake, kernel scheduling and application work.

### Lifecycle, metrics and recycle overhead

`benchmarks/LifecycleRuntimeBench.php` isolates the generic persistent-runtime costs introduced by the 1.0 hardening work:

- request-context creation, bounded request-local attributes and completion;
- request-resetter registry dispatch;
- request metrics start/completion accounting;
- bounded metrics snapshot creation;
- worker recycle accounting at a safe request boundary.

These subjects are intentionally independent from HTTP wire parsing. They make request-lifecycle/resource-policy regressions visible without hiding them inside an end-to-end server result.

Core bounded metrics are part of the runtime contract and are therefore measured as always-on accounting. Debug diagnostics are a separate policy and should not be confused with the fixed-cardinality request counters. When comparing diagnostic configurations, record the `instrumentation` field in comparative evidence explicitly.

### Host adapter overhead

`benchmarks/HostAdapterBench.php` records the common host-runtime adapter costs separately:

- host request normalization into `HttpRequest`;
- `RuntimeApplication` dispatch plus per-request cleanup boundary;
- bounded host response-writer lifecycle.

These measurements do not fabricate FrankenPHP, Swoole or RoadRunner engine throughput with mocks. Engine-specific throughput belongs in a deployment running the real host engine. The PHPBench subjects quantify the Runwire-owned adapter layer that is common to those hosted modes.

### Native HTTP/3 transport

The QUIC-present CI lane performs a real aioquic-to-Runwire HTTP/3 soak over one native QUIC connection. The first 32 requests are treated as warmup and the remaining requests report measured elapsed time, requests/second and amortized microseconds/request. The same run still validates every response and requires bounded GOAWAY/drain completion.

`benchmarks/http3_server.php` and `benchmarks/aioquic_http3_bench.py` provide a dedicated reproducible loopback harness for longer manual runs. They use the production `PhpQuicHttp3Worker`; there is no benchmark-only Runwire transport path.

## Automated PHPBench evidence

`.github/workflows/benchmarks.yml` runs the PHPBench suite on PHP 8.4 and PHP 8.5 and uploads two artifacts:

- `runwire-benchmarks-php-8.4`;
- `runwire-benchmarks-php-8.5`.

Each artifact contains the raw PHPBench JSON and environment metadata (`php -v`, Composer version, kernel and CPU information). Retention is 61 days.

Equivalent local command:

```bash
vendor/bin/phpbench run \
  --report=aggregate \
  --output=json \
  --progress=none \
  --bootstrap=vendor/autoload.php \
  --config=vendor/infocyph/phpforge/resources/phpbench.json \
  benchmarks
```

PHPForge's `ic:benchmark`, `ic:bench:run` and `ic:bench:quick` commands can also be used during development. The dedicated Runwire workflow exists so PR benchmark evidence is recorded even though PHPForge's reusable benchmark job is intentionally conditional on its own benchmark inputs/main-branch reporting flow.

### Latest pre-J regression snapshot

Batch I exact head `1d1ff98cb53facf39b9af98cd7a6cc2c559fb7a9` passed Benchmarks #56. Its artifacts provide a stable pre-J reference for the existing subjects. Representative aggregate modes are shown below in microseconds/op as emitted by PHPBench:

| Subject | PHP 8.4 | PHP 8.5 |
| --- | ---: | ---: |
| Host application dispatch + cleanup | 1.275 | 2.469 |
| Host request normalization | 6.582 | 12.943 |
| Host response-writer lifecycle | 0.703 | 1.425 |
| HTTP/1 head validation | 1.088 | 2.256 |
| HTTP/2 HPACK decode | 20.604 | 41.959 |
| HTTP/2 HPACK encode | 13.532 | 27.744 |
| HTTP/3 frame decode | 0.539 | 1.147 |
| HTTP/3 frame encode | 0.121 | 0.227 |
| HTTP/3 QPACK dynamic round trip | 143.188 | 283.815 |

The PHP 8.4 and PHP 8.5 rows came from different GitHub-hosted CPU models, so **do not interpret the columns as a PHP-version shootout**. They are per-environment regression references only. The final PR-head workflow supersedes this snapshot for the release candidate.

## Reproducing the native HTTP/3 benchmark

Requirements are the same as the supported native HTTP/3 adapter: PHP 8.4/8.5, `ext-quic`, OpenSSL 3.5+, a local certificate/key and Python with `aioquic==1.3.0`.

A representative run uses 32 warmup requests followed by 256 measured requests over one QUIC connection:

```bash
php benchmarks/http3_server.php \
  8443 /path/to/cert.pem /path/to/key.pem /tmp/runwire-h3-ready 288 &

python benchmarks/aioquic_http3_bench.py 8443 256 32
```

The client emits JSON containing `requests`, `warmup_requests`, `elapsed_ms`, `requests_per_second` and `amortized_us_per_request`.

## Rolling reload and recycle measurements

Operational lifecycle measurements must use real supervised workers rather than a synthetic branch inside production code.

For rolling reload, record at minimum:

- steady-state successful request rate before reload;
- minimum successful request rate during replacement;
- time from reload request to replacement generation readiness;
- time until old-generation drain completes;
- request/error totals during the window;
- configured `maxSurge`, `maxUnavailable`, readiness timeout and drain timeout.

A valid zero-unavailable configuration should show replacement readiness before old capacity is retired. Report the capacity dip/recovery curve; do not summarize it as one RPS number.

For recycle overhead, record request rate and latency before, during and after request-count, memory or lifetime-triggered worker replacement. Include the recycle reason and effective jittered threshold. Planned recycle exits must not be mixed into crash-restart statistics.

The Batch I acceptance suite exercises repeated reload/recycle/drain correctness and child reaping. Performance sampling should be run on the deployment class being evaluated because process scheduling noise on shared CI runners can dominate short lifecycle windows.

## Reproducible cross-runtime evidence

`benchmarks/comparative_evidence.php` validates and renders independently collected runtime/server evidence. It deliberately refuses a comparison unless these fields match across every record:

```text
hardware_id
php_version
protocol
workload
workers
concurrency
duration_seconds
```

Every evidence record must also contain:

```text
runtime
runtime_version
instrumentation
throughput_rps
latency_ms.p50
latency_ms.p95
latency_ms.p99
errors_total
error_rate
cpu_percent
rss_peak_bytes
```

Example:

```json
{
  "runtime": "runwire-native",
  "runtime_version": "1.0-pr",
  "protocol": "http/1.1",
  "workload": "plaintext-minimal",
  "hardware_id": "bench-host-01",
  "php_version": "8.4.25",
  "instrumentation": "default",
  "workers": 4,
  "concurrency": 128,
  "duration_seconds": 60,
  "throughput_rps": 0,
  "latency_ms": {"p50": 0, "p95": 0, "p99": 0},
  "errors_total": 0,
  "error_rate": 0,
  "cpu_percent": 0,
  "rss_peak_bytes": 0
}
```

The zero values above are a **schema example, not benchmark evidence**. Never publish them as results.

Render two or more completed records with:

```bash
php benchmarks/comparative_evidence.php runwire.json workerman.json openswoole.json
```

Relevant comparison candidates include Workerman, Swoole/OpenSwoole where directly comparable, FrankenPHP worker mode, RoadRunner and applicable Octane-backed modes. Use the real runtime/server and the same application workload on the same host. A Runwire host-adapter PHPBench result is not a substitute for running the actual host engine.

Separate at least these workload classes when collecting comparative evidence:

- plaintext/minimal handler;
- JSON response and JSON request body;
- streaming request/response;
- concurrency/multiplexing where the compared protocol supports it.

Run native HTTP/1.1, HTTP/2 and HTTP/3 separately when equivalent peer/runtime configurations exist. If a peer cannot expose an equivalent protocol, mark the comparison inapplicable instead of silently substituting HTTP/1.1.

## Benchmark integrity rules

Release benchmarks must obey these rules:

1. Do not add production branches, disabled validation, larger hidden limits or alternative codecs only for benchmarks.
2. Do not compare in-process PHPBench numbers directly with end-to-end transport throughput.
3. Keep HTTP/3 QUIC/TLS cost separate from QPACK/frame microbenchmarks.
4. Keep host adapter cost separate from the host engine's own networking/event-loop cost.
5. Record PHP/runtime version, worker count, concurrency and environment alongside results.
6. Record p50/p95/p99, errors, CPU and RSS with throughput for comparative runs.
7. Treat GitHub-hosted-runner changes as environment changes before concluding that a small timing shift is a Runwire regression.
8. Investigate sustained regressions with profiling before changing safety limits or protocol behavior.
9. Do not rank runtimes by RPS alone.
10. Do not describe Runwire as the fastest PHP framework/runtime from Runwire-only evidence.
11. Do not present Runwire runtime-layer results as Foundation/Webrick full-framework results.

## Runwire 1.0 PR baseline and release evidence

The final PR-head benchmark workflow is the authoritative Runwire-only regression baseline for this 1.0 hardening program. Cross-runtime performance positioning requires separately populated comparative evidence records from equivalent real deployments.

The PR may be technically green without publishing a performance ranking. A release or public performance claim must not invent missing peer results; the explicit release-approval step remains the point at which external comparative evidence and positioning are reviewed.
