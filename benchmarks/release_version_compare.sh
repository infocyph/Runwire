#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 4 ]; then
  echo "Usage: $0 <control-root> <v1-root> <v2-root> <output-dir>" >&2
  exit 2
fi

control_root="$(cd "$1" && pwd)"
v1_root="$(cd "$2" && pwd)"
v2_root="$(cd "$3" && pwd)"
output_dir="$4"
mkdir -p "$output_dir"

concurrency=16
duration_seconds=180
warmup_seconds=30

v1_sha="$(git -C "$v1_root" rev-parse HEAD)"
v2_sha="$(git -C "$v2_root" rev-parse HEAD)"

php -d opcache.enable_cli=1 -r '
foreach (["pcntl", "posix", "event", "Zend OPcache"] as $extension) {
    if (!extension_loaded($extension)) {
        throw new RuntimeException("Missing comparison extension: " . $extension);
    }
}
if (!function_exists("opcache_get_status") || opcache_get_status(false) === false) {
    throw new RuntimeException("CLI OPcache is not enabled for the comparison.");
}
'

cp "$control_root/benchmarks/http1_server.php" "$v1_root/benchmarks/http1_server.php"
cp "$control_root/benchmarks/http1_server.php" "$v2_root/benchmarks/http1_server.php"

find_port() {
  php -r '
$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
if (!is_resource($server)) {
    throw new RuntimeException($error !== "" ? $error : "Unable to allocate benchmark port.");
}
$name = stream_socket_get_name($server, false);
fclose($server);
if (!is_string($name) || !str_contains($name, ":")) {
    throw new RuntimeException("Unable to resolve benchmark port.");
}
echo substr(strrchr($name, ":"), 1);
'
}

v1_port="$(find_port)"
v2_port="$(find_port)"
v1_pid=""
v2_pid=""

cleanup() {
  for pid in "$v1_pid" "$v2_pid"; do
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

wait_ready() {
  local port="$1"
  local pid="$2"
  local log="$3"

  for _ in $(seq 1 300); do
    if php -r '
set_error_handler(static fn(): bool => true);
$socket = stream_socket_client(
    "tcp://127.0.0.1:" . $argv[1],
    $errno,
    $error,
    0.05,
);
restore_error_handler();
if (!is_resource($socket)) {
    exit(1);
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

start_server "$v1_root" "$v1_port" "$output_dir/v1-server.log"
v1_pid="$LAST_PID"
start_server "$v2_root" "$v2_port" "$output_dir/v2-server.log"
v2_pid="$LAST_PID"

wait_ready "$v1_port" "$v1_pid" "$output_dir/v1-server.log"
wait_ready "$v2_port" "$v2_pid" "$output_dir/v2-server.log"

run_trial() {
  local version="$1"
  local build="$2"
  local port="$3"
  local pid="$4"
  local warmup="$5"
  local result="$6"

  RUNWIRE_RUNTIME_VERSION="$version" \
  RUNWIRE_RUNTIME_BUILD="$build" \
  RUNWIRE_INSTRUMENTATION="release-tag-1.0-vs-2.0" \
    php -d opcache.enable_cli=1 "$control_root/benchmarks/http1_sustained_bench.php" \
      "$port" "$concurrency" "$warmup" "$duration_seconds" "$pid" \
      > "$result"

  jq -e '
    .correctness_passed == true
    and .requests_total > 0
    and .completed_requests == .requests_total
    and .successful_requests == .requests_total
    and .errors_total == 0
    and .timeouts_total == 0
    and .validation_failures == 0
  ' "$result" >/dev/null
}

v1_results=()
v2_results=()

for trial in 1 2 3 4 5; do
  warmup=0
  if [ "$trial" -eq 1 ]; then
    warmup="$warmup_seconds"
  fi

  v1_result="$output_dir/v1-trial-$trial.json"
  v2_result="$output_dir/v2-trial-$trial.json"

  if [ $((trial % 2)) -eq 1 ]; then
    run_trial "1.0" "$v1_sha" "$v1_port" "$v1_pid" "$warmup" "$v1_result"
    run_trial "2.0" "$v2_sha" "$v2_port" "$v2_pid" "$warmup" "$v2_result"
  else
    run_trial "2.0" "$v2_sha" "$v2_port" "$v2_pid" "$warmup" "$v2_result"
    run_trial "1.0" "$v1_sha" "$v1_port" "$v1_pid" "$warmup" "$v1_result"
  fi

  v1_results+=("$v1_result")
  v2_results+=("$v2_result")
done

php "$control_root/benchmarks/sustained_summary.php" "${v1_results[@]}" \
  > "$output_dir/v1-summary.json"
php "$control_root/benchmarks/sustained_summary.php" "${v2_results[@]}" \
  > "$output_dir/v2-summary.json"

php "$control_root/benchmarks/regression_compare.php" \
  "$output_dir/v1-summary.json" \
  "$output_dir/v2-summary.json" \
  > "$output_dir/comparison.json"

jq -e '.budget_enforced == true and .passed == true' "$output_dir/comparison.json" >/dev/null

cat "$output_dir/v1-summary.json"
cat "$output_dir/v2-summary.json"
cat "$output_dir/comparison.json"
