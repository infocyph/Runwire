#!/usr/bin/env bash
# Real C-based response-verified load; independent pinned generator CPU headroom.
set -euo pipefail
out="${1:?Usage: c4_wrk_capacity_sweep.sh <artifact-dir>}"
mkdir -p "$out"
out="$(cd "$out" && pwd)"
root="$(cd "$(dirname "$0")/.." && pwd)"
for binary in jq wrk taskset python3; do command -v "$binary" >/dev/null; done
sha="$(git -C "$root" rev-parse HEAD)"
python3 "$root/benchmarks/release_cpu_affinity.py" > "$out/affinity.json"
jq -e '.client_separate_physical_core == true and .server_cpu != .client_cpu' "$out/affinity.json" >/dev/null
server_cpu="$(jq -r '.server_cpu' "$out/affinity.json")"
client_cpu="$(jq -r '.client_cpu' "$out/affinity.json")"
port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m);if(!is_resource($s))throw new RuntimeException($m);$n=stream_socket_get_name($s,false);fclose($s);echo substr(strrchr($n,":"),1);')"
server_pid=""
sampler_pid=""
marker=""
cleanup() {
  if [ -n "$marker" ]; then rm -f "$marker"; fi
  if [ -n "$sampler_pid" ]; then wait "$sampler_pid" 2>/dev/null || true; fi
  if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
    kill -TERM "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
}
trap cleanup EXIT
(
  cd "$root"
  exec taskset -c "$server_cpu" php -d opcache.enable_cli=1 benchmarks/http1_server.php "$port"
) > "$out/server.log" 2>&1 &
server_pid=$!
for attempt in $(seq 1 180); do
  if ! kill -0 "$server_pid" 2>/dev/null; then
    cat "$out/server.log" >&2
    exit 1
  fi
  if php -r '$s=stream_socket_client("tcp://127.0.0.1:".$argv[1],$e,$m,0.05);if(!is_resource($s))exit(1);fclose($s);' "$port" 2>/dev/null; then
    break
  fi
  if [ "$attempt" -eq 180 ]; then echo "Native H1 server not ready" >&2; exit 1; fi
  sleep 0.04
done

for concurrency in 1 4 16 32 64; do
  marker="$out/sampling-$concurrency.flag"
  touch "$marker"
  python3 "$root/benchmarks/c4_process_resource_sampler.py" "$server_pid" "$marker" \
    "$out/server-resources-$concurrency.json" &
  sampler_pid=$!
  /usr/bin/time -f '%U %S %e' -o "$out/generator-$concurrency.time" \
    taskset -c "$client_cpu" wrk -t1 -c "$concurrency" -d12s --latency \
      -s "$root/benchmarks/c4_exact_response.lua" \
      "http://127.0.0.1:$port/benchmark" > "$out/wrk-$concurrency.log"
  rm -f "$marker"
  marker=""
  wait "$sampler_pid"
  sampler_pid=""
  sed -n 's/^RUNWIRE_METRIC://p' "$out/wrk-$concurrency.log" \
    > "$out/client-$concurrency.json"
  python3 - "$out/generator-$concurrency.time" "$out/generator-$concurrency.json" <<'PY'
import json
import pathlib
import sys
parts = pathlib.Path(sys.argv[1]).read_text().split()
if len(parts) != 3:
    raise SystemExit("Real pinned generator CPU timing unavailable")
user, system, elapsed = [float(x) for x in parts]
if elapsed <= 0:
    raise SystemExit("Real generator timing unavailable")
pathlib.Path(sys.argv[2]).write_text(json.dumps({
    "generator_cpu_percent_one_core": round(100 * (user + system) / elapsed, 3),
    "elapsed_seconds": elapsed
}) + "\n")
PY
  jq -n --arg sha "$sha" --argjson concurrency "$concurrency" \
    --slurpfile client "$out/client-$concurrency.json" \
    --slurpfile server "$out/server-resources-$concurrency.json" \
    --slurpfile generator "$out/generator-$concurrency.json" \
    '{source_sha:$sha, concurrency:$concurrency, protocol:"http/1.1",
      workload:"real-C-generator-H1-verified-exact-response",
      generator_headroom_verified:($generator[0].generator_cpu_percent_one_core < 85),
      generator_cpu_percent_one_core:$generator[0].generator_cpu_percent_one_core,
      generator_isolated_physical_core:true,
      successful_requests:$client[0].successful_requests,
      requests_total:$client[0].requests_total,
      throughput_rps:$client[0].throughput_rps,
      latency_ms:$client[0].latency_ms,
      errors_total:$client[0].errors_total,
      timeouts_total:$client[0].timeouts_total,
      validation_failures:$client[0].validation_failures,
      correctness_passed:$client[0].correctness_passed,
      cpu_percent:$server[0].cpu_percent,
      rss_peak_bytes:$server[0].rss_peak_bytes,
      fd_peak_count:$server[0].fd_peak_count,
      samples:$server[0].samples}' > "$out/h1-concurrency-$concurrency.json"
  jq -e '.correctness_passed == true and
    .successful_requests > 0 and .successful_requests == .requests_total
    and .errors_total == 0 and .timeouts_total == 0
    and .validation_failures == 0 and .throughput_rps > 0
    and .latency_ms.p50 > 0 and .latency_ms.p95 > 0 and .latency_ms.p99 > 0
    and .cpu_percent >= 0 and .rss_peak_bytes > 0 and .fd_peak_count > 0
    and .samples >= 10 and .generator_headroom_verified == true' \
    "$out/h1-concurrency-$concurrency.json" >/dev/null || {
      echo "::error::Independent C generator failed correctness or actual CPU headroom at $concurrency." >&2
      cat "$out/h1-concurrency-$concurrency.json" >&2
      exit 1
    }
done
jq -s --arg sha "$sha" --slurpfile topology "$out/affinity.json" \
  '{schema:1,source_sha:$sha,release_certified:false,
    generator_headroom_verified:all(.[];.generator_headroom_verified),
    affinity:$topology[0],samples:.}' \
  "$out"/h1-concurrency-{1,4,16,32,64}.json > "$out/concurrency-summary.json"
jq -e '.generator_headroom_verified == true and (.samples|length) == 5
  and ([.samples[]|select(.correctness_passed != true)]|length)==0' \
  "$out/concurrency-summary.json" >/dev/null
cat "$out/concurrency-summary.json"
