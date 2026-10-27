#!/usr/bin/env bash
# Native vs real RoadRunner hosted kernel syscall tracing enabled/disabled.
set -euo pipefail
out="${1:?Usage: c4_trace_cost.sh <roadrunner-executable> <output-dir>}"
rr="$(realpath "$1")"
mkdir -p "$2"
out="$(cd "$2" && pwd)"
repo="$(cd "$(dirname "$0")/.." && pwd)"
sha="$(git -C "$repo" rev-parse HEAD)"
for executable in wrk strace taskset jq python3; do command -v "$executable" >/dev/null; done
python3 "$repo/benchmarks/release_cpu_affinity.py" > "$out/affinity.json"
jq -e '.client_separate_physical_core == true' "$out/affinity.json" >/dev/null
server_cpu="$(jq -r '.server_cpu' "$out/affinity.json")"
client_cpu="$(jq -r '.client_cpu' "$out/affinity.json")"
test -x "$rr"
tmp="$(mktemp -d)"
pid=""
sampler_pid=""
sample_flag=""
cleanup() {
  if [ -n "$sample_flag" ]; then rm -f "$sample_flag"; fi
  if [ -n "$sampler_pid" ]; then wait "$sampler_pid" 2>/dev/null || true; fi
  if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
    kill -TERM -- "-$pid" 2>/dev/null || kill -TERM "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
  fi
  rm -rf "$tmp"
}
trap 'status=$?; if [ "$status" -ne 0 ]; then
  echo "::error::C4 trace-on/off step failed (phase: ${runtime:-init}/${tracing:-init}; code=$status)" >&2
  for serverLog in "$out"/*-server.log; do
    if [ -f "$serverLog" ]; then
      echo "--- $serverLog ---" >&2
      tail -80 "$serverLog" >&2 || true
    fi
  done
fi
cleanup' EXIT
cd "$repo"
for runtime in native roadrunner; do
  for tracing in off on; do
    echo "Starting source-pinned C4 trace comparison $runtime/$tracing"
    port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m);if(!is_resource($s))throw new RuntimeException($m);$n=stream_socket_get_name($s,false);fclose($s);echo substr(strrchr($n,":"),1);')"
    if [ "$runtime" = native ]; then
      server=(php -d opcache.enable_cli=1 benchmarks/http1_server.php "$port")
      url="http://127.0.0.1:$port/benchmark"
    else
      cat > "$tmp/rr.yaml" <<YAML
version: '3'
server:
  command: "php $repo/tests/fixtures/roadrunner-live/index.php"
  relay: pipes
  env:
    RR_MODE: "http"
    RR_RELAY: "pipes"
http:
  address: 127.0.0.1:$port
  pool:
    num_workers: 1
    max_jobs: 0
logs:
  level: error
YAML
      server=("$rr" serve -c "$tmp/rr.yaml")
      url="http://127.0.0.1:$port/rr?case=bench"
    fi
    if [ "$tracing" = on ]; then
      setsid taskset -c "$server_cpu" strace -ff -qq -e trace=network,read,write,readv,writev \
        -o "$out/$runtime-trace" "${server[@]}" > "$out/$runtime-on-server.log" 2>&1 &
    else
      setsid taskset -c "$server_cpu" "${server[@]}" > "$out/$runtime-off-server.log" 2>&1 &
    fi
    pid=$!
    echo "Started $runtime/$tracing real server pid=$pid (client CPU=$client_cpu server CPU=$server_cpu)"
    ready=0
    for _ in $(seq 1 200); do
      if ! kill -0 "$pid" 2>/dev/null; then
        cat "$out/$runtime-$tracing-server.log" >&2
        exit 1
      fi
      if curl --fail --silent --show-error --max-time 2 "$url" > "$tmp/ready" 2>/dev/null; then
        if [ "$(cat "$tmp/ready")" = ok ] || grep -q '"pool_roundtrip":true' "$tmp/ready"; then
          ready=1
          break
        fi
      fi
      sleep 0.04
    done
    if [ "$ready" -ne 1 ]; then echo "Real $runtime $tracing trace peer not ready" >&2; exit 1; fi
    sample_flag="$out/sampling-$runtime-$tracing.flag"
    touch "$sample_flag"
    python3 benchmarks/c4_process_resource_sampler.py "$pid" "$sample_flag" \
      "$out/$runtime-$tracing-resources.json" &
    sampler_pid=$!
    taskset -c "$client_cpu" wrk -t1 -c4 -d12s --latency \
      -s benchmarks/c4_trace_response.lua "$url" > "$out/$runtime-$tracing-client.log"
    rm -f "$sample_flag"
    sample_flag=""
    wait "$sampler_pid"
    sampler_pid=""
    sed -n 's/^RUNWIRE_METRIC://p' "$out/$runtime-$tracing-client.log" \
      > "$out/$runtime-$tracing-client.json"
    test "$(wc -l < "$out/$runtime-$tracing-client.json")" -eq 1
    jq -e '.correctness_passed == true and .successful_requests == .requests_total
      and .errors_total == 0 and .timeouts_total == 0
      and .validation_failures == 0 and .latency_ms.p99 > 0
      and .throughput_rps > 0' "$out/$runtime-$tracing-client.json" >/dev/null
    jq -e '.samples >= 10 and .rss_peak_bytes > 0 and
      .fd_peak_count > 0 and .cpu_percent >= 0' "$out/$runtime-$tracing-resources.json" >/dev/null
    if [ "$tracing" = on ]; then
      find "$out" -maxdepth 1 -name "$runtime-trace.*" -type f -print \
        > "$out/$runtime-trace-files.txt"
      test -s "$out/$runtime-trace-files.txt"
      find "$out" -maxdepth 1 -name "$runtime-trace.*" -type f \
        -exec wc -l '{}' '+' > "$out/$runtime-trace-line-counts.txt"
      # Keep individual syscall files in the retained raw GitHub artifact.
    fi
    kill -TERM -- "-$pid" 2>/dev/null || kill -TERM "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
    pid=""
  done
  jq -n --arg runtime "$runtime" --arg sha "$sha" \
    --slurpfile off "$out/$runtime-off-client.json" \
    --slurpfile on "$out/$runtime-on-client.json" \
    --slurpfile offResources "$out/$runtime-off-resources.json" \
    --slurpfile onResources "$out/$runtime-on-resources.json" \
    '{source_sha:$sha,runtime:$runtime,trace_type:"kernel-strace-network-read-write",
      trace_off:$off[0],trace_on:$on[0],
      off_resources:$offResources[0],on_resources:$onResources[0],
      throughput_retained_ratio:($on[0].throughput_rps/$off[0].throughput_rps),
      p99_ratio:($on[0].latency_ms.p99/$off[0].latency_ms.p99),
      rss_ratio:($onResources[0].rss_peak_bytes/$offResources[0].rss_peak_bytes),
      trace_correctness:($on[0].correctness_passed and $off[0].correctness_passed),
      bounded_external_trace_overhead:
        ($on[0].throughput_rps/$off[0].throughput_rps >= 0.20 and
         $on[0].latency_ms.p99 <= ($off[0].latency_ms.p99*12+50) and
         $onResources[0].rss_peak_bytes <= ($offResources[0].rss_peak_bytes*2+67108864))
    }' > "$out/$runtime-comparison.json"
  jq -e '.trace_correctness == true and .bounded_external_trace_overhead == true' \
    "$out/$runtime-comparison.json" >/dev/null || {
      cat "$out/$runtime-comparison.json" >&2
      echo "::error::Real $runtime kernel trace overhead exceeded predeclared C4 budgets." >&2
      exit 1
    }
  cat "$out/$runtime-comparison.json"
done
