#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 3 ]; then
  echo "Usage: $0 <baseline-dir> <candidate-dir> <output-dir>" >&2
  exit 2
fi

baseline_dir="$(cd "$1" && pwd)"
candidate_dir="$(cd "$2" && pwd)"
output_dir="$3"
mkdir -p "$output_dir"

baseline_build="${RUNWIRE_BASELINE_BUILD:-7e9bf20ad0737a187dfed77b723791b141b36378}"
candidate_build="${RUNWIRE_CANDIDATE_BUILD:-unknown}"
php_version="$(php -r 'echo PHP_VERSION;')"
extension_versions="$(php -r '
$extensions = ["pcntl", "posix", "event", "Zend OPcache"];
$values = [];
foreach ($extensions as $extension) {
    if (!extension_loaded($extension)) {
        continue;
    }
    $version = phpversion($extension);
    $values[] = $extension . "=" . (is_string($version) && $version !== "" ? $version : "builtin");
}
echo implode(",", $values);
')"
opcache="$(php -d opcache.enable_cli=1 -r 'echo function_exists("opcache_get_status") && opcache_get_status(false) !== false ? "enabled-cli" : "disabled";')"

if [ "$opcache" != "enabled-cli" ]; then
  echo "OPcache must be enabled for matched release certification." >&2
  exit 1
fi

if ! command -v taskset >/dev/null 2>&1 || ! command -v python3 >/dev/null 2>&1; then
  echo "Matched certification requires Linux taskset and Python 3 for verifiable CPU isolation." >&2
  exit 1
fi

# Select two different physical cores from the actual runner cpuset. Both
# baseline and candidate servers use the SAME server core, sequentially;
# the benchmark client uses a separate physical core in every trial.
affinity_json="$(python3 - <<'PY'
import json
import os
from pathlib import Path

allowed = sorted(os.sched_getaffinity(0))
physical = {}
for cpu in allowed:
    topology = Path(f"/sys/devices/system/cpu/cpu{cpu}/topology")
    core = (topology / "core_id").read_text().strip()
    package = (topology / "physical_package_id").read_text().strip()
    physical.setdefault((package, core), cpu)
if len(physical) < 2:
    raise SystemExit("Matched certification needs two allowed distinct physical CPU cores.")
servers = list(physical.values())
print(json.dumps({
    "mode": "pinned_distinct_physical_cores",
    "server_cpu": servers[0],
    "client_cpu": servers[1],
    "allowed_logical_cpu_count": len(allowed),
    "available_physical_core_count": len(physical),
    "same_server_cpu_for_baseline_and_candidate": True,
    "client_separate_physical_core": True,
}, separators=(",", ":")))
PY
)"
printf '%s\n' "$affinity_json" | jq -e \
  '.client_separate_physical_core == true and .same_server_cpu_for_baseline_and_candidate == true' >/dev/null
printf '%s\n' "$affinity_json" > "$output_dir/cpu-affinity.json"
server_cpu="$(jq -r '.server_cpu' "$output_dir/cpu-affinity.json")"
client_cpu="$(jq -r '.client_cpu' "$output_dir/cpu-affinity.json")"

baseline_pid=""
candidate_pid=""

cleanup() {
  for pid in "$baseline_pid" "$candidate_pid"; do
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
      kill -TERM "$pid" 2>/dev/null || true
      for _ in $(seq 1 100); do
        if ! kill -0 "$pid" 2>/dev/null; then
          break
        fi
        sleep 0.02
      done
      if kill -0 "$pid" 2>/dev/null; then
        kill -KILL "$pid" 2>/dev/null || true
      fi
      wait "$pid" 2>/dev/null || true
    fi
  done
}
trap cleanup EXIT

find_port() {
  php -r '
  $server = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
  if (!is_resource($server)) {
      throw new RuntimeException($error);
  }
  $name = stream_socket_get_name($server, false);
  fclose($server);
  if (!is_string($name) || !str_contains($name, ":")) {
      throw new RuntimeException("Unable to resolve ephemeral release-certification port.");
  }
  echo substr(strrchr($name, ":"), 1);
  '
}

wait_ready() {
  local port="$1"
  local pid="$2"
  local log="$3"
  for _ in $(seq 1 300); do
    if php -r '
    $socket = @stream_socket_client("tcp://127.0.0.1:" . $argv[1], $errno, $error, 0.05);
    if (!is_resource($socket)) {
        throw new RuntimeException($error);
    }
    fclose($socket);
    ' "$port" >/dev/null 2>&1
    then
      return 0
    fi
    if ! kill -0 "$pid" 2>/dev/null; then
      cat "$log" >&2
      return 1
    fi
    sleep 0.02
  done
  echo "Server on port $port did not become ready." >&2
  cat "$log" >&2
  return 1
}

start_server() {
  local root="$1"
  local port="$2"
  local log="$3"
  (
    cd "$root"
    exec taskset -c "$server_cpu" php -d opcache.enable_cli=1 benchmarks/http1_server.php "$port"
  ) >"$log" 2>&1 &
  LAST_PID=$!
}

run_evidence() {
  local label="$1"
  local port="$2"
  local pid="$3"
  local build="$4"
  local warmup="$5"
  local duration="$6"
  local result="$7"

  RUNWIRE_RUNTIME_VERSION="$label" \
  RUNWIRE_RUNTIME_BUILD="$build" \
  RUNWIRE_PHP_VERSION="$php_version" \
  RUNWIRE_INSTRUMENTATION="release-certification-matched-pinned-physical-cores" \
  RUNWIRE_OPCACHE="$opcache" \
  RUNWIRE_EXTENSION_VERSIONS="$extension_versions" \
    taskset -c "$client_cpu" php "$candidate_dir/benchmarks/http1_sustained_bench.php" \
      "$port" 16 "$warmup" "$duration" "$pid" > "$result"

  jq -e '
    .correctness_passed == true
    and .successful_requests == .requests_total
    and .errors_total == 0
    and .timeouts_total == 0
    and .validation_failures == 0
  ' "$result" >/dev/null
}

baseline_port="$(find_port)"
candidate_port="$(find_port)"
start_server "$baseline_dir" "$baseline_port" "$output_dir/baseline-server.log"
baseline_pid="$LAST_PID"
start_server "$candidate_dir" "$candidate_port" "$output_dir/candidate-server.log"
candidate_pid="$LAST_PID"
wait_ready "$baseline_port" "$baseline_pid" "$output_dir/baseline-server.log"
wait_ready "$candidate_port" "$candidate_pid" "$output_dir/candidate-server.log"

# One explicit 30-second warm-up per server before the five measured trials.
run_evidence "2.1.2-baseline" "$baseline_port" "$baseline_pid" "$baseline_build" 30 0.1 "$output_dir/baseline-warmup.json"
run_evidence "2.2-candidate" "$candidate_port" "$candidate_pid" "$candidate_build" 30 0.1 "$output_dir/candidate-warmup.json"

baseline_trials=()
candidate_trials=()
for trial in 1 2 3 4 5; do
  baseline_result="$output_dir/baseline-trial-$trial.json"
  candidate_result="$output_dir/candidate-trial-$trial.json"

  if [ $((trial % 2)) -eq 1 ]; then
    run_evidence "2.1.2-baseline" "$baseline_port" "$baseline_pid" "$baseline_build" 0 180 "$baseline_result"
    run_evidence "2.2-candidate" "$candidate_port" "$candidate_pid" "$candidate_build" 0 180 "$candidate_result"
  else
    run_evidence "2.2-candidate" "$candidate_port" "$candidate_pid" "$candidate_build" 0 180 "$candidate_result"
    run_evidence "2.1.2-baseline" "$baseline_port" "$baseline_pid" "$baseline_build" 0 180 "$baseline_result"
  fi

  baseline_trials+=("$baseline_result")
  candidate_trials+=("$candidate_result")
done

php "$candidate_dir/benchmarks/sustained_summary.php" "${baseline_trials[@]}" > "$output_dir/baseline-summary.json"
php "$candidate_dir/benchmarks/sustained_summary.php" "${candidate_trials[@]}" > "$output_dir/candidate-summary.json"
php "$candidate_dir/benchmarks/regression_compare.php" \
  "$output_dir/baseline-summary.json" \
  "$output_dir/candidate-summary.json" \
  > "$output_dir/comparison.json"

jq -e '.budget_enforced == true and .passed == true' "$output_dir/comparison.json" >/dev/null

cat "$output_dir/baseline-summary.json"
cat "$output_dir/candidate-summary.json"
cat "$output_dir/comparison.json"
