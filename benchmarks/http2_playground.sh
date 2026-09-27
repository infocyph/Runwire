#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 5 ]; then
  echo "Usage: $0 <control-root> <runwire-root> <certificate> <private-key> <output-dir>" >&2
  exit 2
fi

control_root="$(cd "$1" && pwd)"
runwire_root="$(cd "$2" && pwd)"
certificate="$3"
private_key="$4"
output_dir="$5"
mkdir -p "$output_dir"

server_pid=""
ACTIVE_PORT=""
CURRENT_SERVER_LOG=""

cleanup() {
  if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
    kill -TERM "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
}
trap cleanup EXIT

find_port() {
  php -r '
  $server = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
  if (!is_resource($server)) {
      throw new RuntimeException($error !== "" ? $error : "Unable to allocate port.");
  }
  $name = stream_socket_get_name($server, false);
  fclose($server);
  echo substr(strrchr((string) $name, ":"), 1);
  '
}

wait_ready() {
  local port="$1"
  local log="$2"

  for _ in $(seq 1 200); do
    if printf '' | timeout 1 openssl s_client       -connect "127.0.0.1:$port"       -servername localhost       -alpn h2       2>&1 | grep -q 'ALPN protocol: h2'; then
      return 0
    fi

    if ! kill -0 "$server_pid" 2>/dev/null; then
      echo "--- HTTP/2 server exited before ALPN readiness ---" >&2
      cat "$log" >&2
      return 1
    fi
    sleep 0.02
  done

  echo "--- HTTP/2 ALPN readiness timed out ---" >&2
  printf '' | timeout 2 openssl s_client     -connect "127.0.0.1:$port"     -servername localhost     -alpn h2     2>&1 || true
  echo "--- HTTP/2 server log ---" >&2
  cat "$log" >&2
  return 1
}

start_server() {
  local nodelay="$1"
  local payload="$2"
  local label="$3"
  local port
  port="$(find_port)"

  (
    cd "$runwire_root"
    exec env RUNWIRE_H2_MAX_STREAMS_PER_CONNECTION=1000000 php -d opcache.enable_cli=1       "$control_root/benchmarks/http12_lab_server.php"       "$port" "$payload" 1 "$nodelay" "$certificate" "$private_key"
  ) >"$output_dir/$label-server.log" 2>&1 &
  server_pid=$!

  CURRENT_SERVER_LOG="$label-server.log"
  wait_ready "$port" "$output_dir/$CURRENT_SERVER_LOG"
  ACTIVE_PORT="$port"
}

stop_server() {
  cleanup
  server_pid=""
}

measure() {
  local label="$1"
  local nodelay="$2"
  local payload="$3"
  local streams="$4"
  local connections="$5"
  local url="https://127.0.0.1:$ACTIVE_PORT/benchmark"

  if ! h2load -n 256 -c "$connections" -m "$streams" -t 1 "$url"     >"$output_dir/$label-warmup.raw" 2>&1; then
    echo "--- h2load warm-up failure: $label ---" >&2
    cat "$output_dir/$label-warmup.raw" >&2
    echo "--- HTTP/2 server: $label ---" >&2
    cat "$output_dir/$CURRENT_SERVER_LOG" >&2
    return 1
  fi

  if ! h2load -n 1000000 -c "$connections" -m "$streams" -t 1 -D 5 "$url"     >"$output_dir/$label.raw" 2>&1; then
    echo "--- h2load measured failure: $label ---" >&2
    cat "$output_dir/$label.raw" >&2
    echo "--- HTTP/2 server: $label ---" >&2
    cat "$output_dir/$CURRENT_SERVER_LOG" >&2
    return 1
  fi

  if ! php "$control_root/benchmarks/h2load_parse.php"     "$output_dir/$label.raw"     "$nodelay" "$payload" "$streams" "$connections" "$label"     >"$output_dir/$label.json"; then
    echo "--- h2load parse failure: $label ---" >&2
    cat "$output_dir/$label.raw" >&2
    echo "--- HTTP/2 server: $label ---" >&2
    cat "$output_dir/$CURRENT_SERVER_LOG" >&2
    return 1
  fi

  if ! jq -e '.correctness_passed == true and .requests_failed == 0'     "$output_dir/$label.json" >/dev/null; then
    echo "--- HTTP/2 correctness failure: $label ---" >&2
    cat "$output_dir/$label.json" >&2
    cat "$output_dir/$label.raw" >&2
    echo "--- HTTP/2 server: $label ---" >&2
    cat "$output_dir/$CURRENT_SERVER_LOG" >&2
    return 1
  fi
}

for nodelay in 0 1; do
  for payload in 2 1024 16384; do
    prefix="h2-nodelay$nodelay-payload$payload"
    start_server "$nodelay" "$payload" "$prefix"

    for streams in 1 8 32 100; do
      measure "$prefix-m$streams-c1" "$nodelay" "$payload" "$streams" 1
    done

    stop_server
  done
done

start_server 1 2 "h2-connection-scaling"
for connections in 1 4 16; do
  measure "h2-nodelay1-payload2-m32-c$connections" 1 2 32 "$connections"
done
stop_server

boundary_port="$(find_port)"
boundary_log="$output_dir/h2-stream-churn-boundary-server.log"
(
  cd "$runwire_root"
  exec env RUNWIRE_H2_MAX_STREAMS_PER_CONNECTION=10000 php -d opcache.enable_cli=1 \
    "$control_root/benchmarks/http12_lab_server.php" \
    "$boundary_port" 2 1 1 "$certificate" "$private_key"
) >"$boundary_log" 2>&1 &
server_pid=$!
CURRENT_SERVER_LOG="$(basename "$boundary_log")"
wait_ready "$boundary_port" "$boundary_log"
ACTIVE_PORT="$boundary_port"

set +e
h2load -n 10100 -c 1 -m 100 -t 1 "https://127.0.0.1:$ACTIVE_PORT/benchmark" \
  >"$output_dir/h2-stream-churn-boundary.raw" 2>&1
boundary_exit=$?
set -e

php -r '
$raw = file_get_contents($argv[1]);
if (!is_string($raw)) {
    throw new RuntimeException("Unable to read churn-boundary output.");
}
if (preg_match("/requests:\\s+(\\d+) total,\\s+(\\d+) started,\\s+(\\d+) done,\\s+(\\d+) succeeded,\\s+(\\d+) failed,\\s+(\\d+) errored,\\s+(\\d+) timeout/", $raw, $m) !== 1) {
    throw new RuntimeException("Unable to parse churn-boundary counts.");
}
$result = [
    "configured_max_streams_per_connection" => 10000,
    "client_requests" => 10100,
    "requests_total" => (int) $m[1],
    "requests_started" => (int) $m[2],
    "requests_done" => (int) $m[3],
    "requests_succeeded" => (int) $m[4],
    "requests_failed" => (int) $m[5],
    "requests_errored" => (int) $m[6],
    "requests_timed_out" => (int) $m[7],
    "h2load_exit_code" => (int) $argv[2],
    "boundary_observed" => (int) $m[4] === 10000 && (int) $m[5] > 0,
];
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
' "$output_dir/h2-stream-churn-boundary.raw" "$boundary_exit" \
  >"$output_dir/h2-stream-churn-boundary.json"

jq -e '.boundary_observed == true and .requests_timed_out == 0' \
  "$output_dir/h2-stream-churn-boundary.json" >/dev/null

stop_server

jq -s '.' "$output_dir"/*.json > "$output_dir/summary.json"
