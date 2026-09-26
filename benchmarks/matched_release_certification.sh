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

baseline_build="${RUNWIRE_BASELINE_BUILD:-dc78bdc9d8684dcff48dac197d6cc0f21ed86043}"
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
    exec php -d opcache.enable_cli=1 benchmarks/http1_server.php "$port"
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
  RUNWIRE_INSTRUMENTATION="release-certification-matched" \
  RUNWIRE_OPCACHE="$opcache" \
  RUNWIRE_EXTENSION_VERSIONS="$extension_versions" \
    php "$candidate_dir/benchmarks/http1_sustained_bench.php" \
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
run_evidence "2.0-baseline" "$baseline_port" "$baseline_pid" "$baseline_build" 30 0.1 "$output_dir/baseline-warmup.json"
run_evidence "2.0-candidate" "$candidate_port" "$candidate_pid" "$candidate_build" 30 0.1 "$output_dir/candidate-warmup.json"

baseline_trials=()
candidate_trials=()
for trial in 1 2 3 4 5; do
  baseline_result="$output_dir/baseline-trial-$trial.json"
  candidate_result="$output_dir/candidate-trial-$trial.json"

  if [ $((trial % 2)) -eq 1 ]; then
    run_evidence "2.0-baseline" "$baseline_port" "$baseline_pid" "$baseline_build" 0 180 "$baseline_result"
    run_evidence "2.0-candidate" "$candidate_port" "$candidate_pid" "$candidate_build" 0 180 "$candidate_result"
  else
    run_evidence "2.0-candidate" "$candidate_port" "$candidate_pid" "$candidate_build" 0 180 "$candidate_result"
    run_evidence "2.0-baseline" "$baseline_port" "$baseline_pid" "$baseline_build" 0 180 "$baseline_result"
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
