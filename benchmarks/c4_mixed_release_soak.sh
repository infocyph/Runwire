#!/usr/bin/env bash
# Exactly 1800 seconds of independent real HTTP, same-loop file/TCP,
# autoscaling HTTP peers, internal GC/OPcache and Linux whole-tree resource data.
set -euo pipefail

out="${1:?Usage: c4_mixed_release_soak.sh <artifact-directory>}"
mkdir -p "$out"
out="$(cd "$out" && pwd)"
root="$(cd "$(dirname "$0")/.." && pwd)"
source_sha="$(git -C "$root" rev-parse HEAD)"
printf '%s\n' "$source_sha" > "$out/source-sha.txt"
printf '%s\n' "mixed-c4-full-1800-seconds" > "$out/workload.txt"

for bin in php jq curl; do command -v "$bin" >/dev/null; done
port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m);if(!is_resource($s))throw new RuntimeException($m);$n=stream_socket_get_name($s,false);fclose($s);echo substr(strrchr($n,":"),1);')"
server_pid=""
tree_sampler_pid=""
internal_sampler_pid=""
file_pid=""
scale_pid=""
finish="$out/continue-workers"
touch "$finish"
cleanup() {
  rm -f "$finish"
  for pid in "$scale_pid" "$file_pid" "$internal_sampler_pid" "$tree_sampler_pid" "$server_pid"; do
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
      kill -TERM "$pid" 2>/dev/null || true
    fi
  done
  for pid in "$scale_pid" "$file_pid" "$internal_sampler_pid" "$tree_sampler_pid" "$server_pid"; do
    if [ -n "$pid" ]; then
      wait "$pid" 2>/dev/null || true
    fi
  done
}
trap cleanup EXIT

cd "$root"
RUNWIRE_SOAK_DIAGNOSTICS=1 php -d opcache.enable_cli=1 benchmarks/http1_server.php "$port" > "$out/server.log" 2>&1 &
server_pid=$!
ready=0
for _ in $(seq 1 250); do
  if ! kill -0 "$server_pid" 2>/dev/null; then
    cat "$out/server.log" >&2
    exit 1
  fi
  if php -r '$c=stream_socket_client("tcp://127.0.0.1:".$argv[1],$e,$m,0.06);if(!is_resource($c))exit(1);fclose($c);' "$port" 2>/dev/null; then
    ready=1
    break
  fi
  sleep 0.04
done
if [ "$ready" -ne 1 ]; then
  echo "Runwire mixed soak native HTTP server did not become ready." >&2
  exit 1
fi

# Fail early: the internal 1800s GC/OPcache sampler must use the exact
# admitted diagnostics endpoint of the worker, never a fabricated local metric.
curl --fail --show-error --silent --max-time 5 -H 'Connection: close' \
  "http://127.0.0.1:$port/__runwire_soak_diagnostics" \
  > "$out/internal-initial.json"
jq -e '.gc_enabled == true and .opcache_enabled == true
  and .allocated_bytes > 0 and .gc_roots >= 0 and .opcache_used_bytes > 0' \
  "$out/internal-initial.json" >/dev/null

# Reuse the *same* startup and telemetry admission path in quick GitHub CI;
# the actual U15.10 proof still requires every genuine 1800-second workload.
if [ "${RUNWIRE_C4_PREFLIGHT_ONLY:-0}" = "1" ]; then
  printf 'Real mixed-soak telemetry admission verified; full 1800s not run.\n'
  exit 0
fi

# The root process is this orchestrator, whose descendants include all three
# independent workload families, supervisor children and the external samplers.
php benchmarks/soak_resource_profile.php sample "$$" "$out/process-tree.csv" 5 > "$out/tree-sampler.log" 2>&1 &
tree_sampler_pid=$!
php -d opcache.enable_cli=1 benchmarks/soak_runtime_telemetry.php sample \
  "$port" "$server_pid" "$out/internal-gc-opcache.jsonl" 5 \
  > "$out/internal-sampler.log" 2>&1 &
internal_sampler_pid=$!

# Real bounded binary file workers and independent TCP peers on a shared event
# loop overlap with production native HTTP requests throughout the entire soak.
(
  count=0
  while [ -f "$finish" ]; do
    count=$((count + 1))
    RUNWIRE_RUNTIME_BUILD="$source_sha" php -d opcache.enable_cli=1 \
      benchmarks/c4_mixed_file_network.php > "$out/file-tcp-current.json"
    jq -e '.correctness_passed == true and .network_connected == 48
      and .completed_binary_file_roundtrips == 48 and
      .peak_timer_tick_gap_ms < 150 and .max_parent_fds < 256' \
      "$out/file-tcp-current.json" >/dev/null
    printf '%s\n' "$count" > "$out/file-tcp-passed-iterations.txt"
    sleep 2
  done
) > "$out/file-tcp-loop.log" 2>&1 &
file_pid=$!

# Real independently admitted native HTTP requests and controlled worker
# scale-in/scale-out are exercised repeatedly; no fabricated autoscale values.
(
  count=0
  while [ -f "$finish" ]; do
    count=$((count + 1))
    php -d opcache.enable_cli=1 .ci/c3_autoscale_http_traffic.php \
      > "$out/autoscale-current.json"
    jq -e '.success >= 60 and .success == .attemptedWhileReady
      and .failures == 0 and .scaleInObserved == true
      and .scaleOutObserved == true and .remainingWorkers == 0' \
      "$out/autoscale-current.json" >/dev/null
    printf '%s\n' "$count" > "$out/autoscale-passed-iterations.txt"
  done
) > "$out/autoscale-loop.log" 2>&1 &
scale_pid=$!

export RUNWIRE_RUNTIME_BUILD="$source_sha"
export RUNWIRE_RUNTIME_VERSION="3.0-candidate"
export RUNWIRE_OPCACHE="enabled-cli"
export RUNWIRE_INSTRUMENTATION="real-mixed-1800s-release-soak"
# Continuous actual 1800-second client request stream (not 1800s of sleep).
php -d opcache.enable_cli=1 benchmarks/http1_sustained_bench.php \
  "$port" 16 30 1800 "$server_pid" > "$out/h1-1800.json"
jq -e '.correctness_passed == true and .duration_seconds == 1800 and
  .errors_total == 0 and .timeouts_total == 0 and .validation_failures == 0
  and .successful_requests == .requests_total and
  .successful_requests > 0 and .latency_ms.p99 > 0' "$out/h1-1800.json" >/dev/null

# Stop only admission of new iterations; drain the already accepted groups.
rm -f "$finish"
wait "$file_pid"
file_pid=""
wait "$scale_pid"
scale_pid=""
test "$(cat "$out/file-tcp-passed-iterations.txt")" -ge 10
test "$(cat "$out/autoscale-passed-iterations.txt")" -ge 20
test -s "$out/internal-gc-opcache.jsonl"
test -s "$out/process-tree.csv"

kill -TERM "$server_pid"
wait "$server_pid"
server_pid=""
# The external sampler watches this orchestrator, so terminate it only once
# the measured workload has drained; its records cover the entire 1800s run.
kill -TERM "$tree_sampler_pid" 2>/dev/null || true
wait "$tree_sampler_pid" || true
tree_sampler_pid=""
wait "$internal_sampler_pid"
internal_sampler_pid=""

php benchmarks/soak_resource_profile.php analyze \
  "$out/process-tree.csv" "$out/process-tree-analysis.json"
php benchmarks/soak_runtime_telemetry.php analyze \
  "$out/internal-gc-opcache.jsonl" "$out/internal-analysis.json"
jq -e '.passed == true and .duration_seconds >= 1740
  and .peak_process_count >= 3' "$out/process-tree-analysis.json" >/dev/null
jq -e '.passed == true and .duration_seconds >= 1740
  and .gc_runs_total_at_end >= 0' "$out/internal-analysis.json" >/dev/null
jq -n --arg sha "$source_sha" \
  --slurpfile h1 "$out/h1-1800.json" \
  --slurpfile tree "$out/process-tree-analysis.json" \
  --slurpfile internals "$out/internal-analysis.json" \
  --argjson file "$(cat "$out/file-tcp-passed-iterations.txt")" \
  --argjson scale "$(cat "$out/autoscale-passed-iterations.txt")" \
  '{source_sha:$sha,full_continuous_network_seconds:1800,
    file_tcp_successful_interleaved_cycles:$file,
    autoscale_successful_interleaved_cycles:$scale,
    h1_successful_requests:$h1[0].successful_requests,
    h1_errors:$h1[0].errors_total,
    process_tree:$tree[0],worker_gc_opcache:$internals[0],
    release_qualified:($tree[0].passed and $internals[0].passed
      and $h1[0].correctness_passed and $file >= 10 and $scale >= 20)}' \
  > "$out/mixed-soak-result.json"
jq -e '.release_qualified == true' "$out/mixed-soak-result.json" >/dev/null
cat "$out/mixed-soak-result.json"
