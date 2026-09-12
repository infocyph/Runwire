# Runwire 1.0 Benchmark Methodology

Runwire benchmarks are release evidence and regression signals. They are not universal capacity claims: GitHub-hosted runners, local developer machines and production hosts have different CPU, kernel, crypto and virtualization characteristics.

The benchmark suite deliberately keeps protocol-core cost, host-adapter cost and native transport cost separate. Combining them into one headline number would hide where time is actually spent.

## Benchmark layers

### Protocol core

`benchmarks/ProtocolCoreBench.php` records deterministic PHPBench subjects for:

- HTTP/1.1 request-head validation;
- HTTP/2 HPACK encode/decode;
- HTTP/3 frame encode/decode;
- HTTP/3 QPACK static encode/decode;
- HTTP/3 QPACK dynamic-table round trip including encoder/decoder instruction exchange.

These are in-process protocol measurements. They intentionally exclude sockets, TLS, QUIC handshake, kernel scheduling and application work.

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

## Reproducing the native HTTP/3 benchmark

Requirements are the same as the supported native HTTP/3 adapter: PHP 8.4/8.5, `ext-quic`, OpenSSL 3.5+, a local certificate/key and Python with `aioquic==1.3.0`.

A representative run uses 32 warmup requests followed by 256 measured requests over one QUIC connection:

```bash
php benchmarks/http3_server.php \
  8443 /path/to/cert.pem /path/to/key.pem /tmp/runwire-h3-ready 288 &

python benchmarks/aioquic_http3_bench.py 8443 256 32
```

The client emits JSON containing `requests`, `warmup_requests`, `elapsed_ms`, `requests_per_second` and `amortized_us_per_request`.

## Benchmark integrity rules

Release benchmarks must obey these rules:

1. Do not add production branches, disabled validation, larger hidden limits or alternative codecs only for benchmarks.
2. Do not compare in-process PHPBench numbers directly with end-to-end transport throughput.
3. Keep HTTP/3 QUIC/TLS cost separate from QPACK/frame microbenchmarks.
4. Keep host adapter cost separate from the host engine's own networking/event-loop cost.
5. Record PHP version and environment alongside results.
6. Treat GitHub-hosted-runner changes as environment changes before concluding that a small timing shift is a Runwire regression.
7. Investigate sustained regressions with profiling before changing safety limits or protocol behavior.

## Runwire 1.0 release baseline

The final Runwire 1.0 baseline is recorded from the final green release-head benchmark workflow and native HTTP/3 CI output. The release-plan tracker should reference that exact workflow run rather than copying a result from an earlier development head.
