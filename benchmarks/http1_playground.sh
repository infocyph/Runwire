#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -lt 5 ]; then
  echo "Usage: $0 <control-root> <runwire-root> <output-dir> <backend-label> <plain|tls> [cert] [key]" >&2
  exit 2
fi

control_root="$(cd "$1" && pwd)"
runwire_root="$(cd "$2" && pwd)"
output_dir="$3"
backend="$4"
transport="$5"
certificate="${6:--}"
private_key="${7:--}"
mkdir -p "$output_dir"

server_pid=""

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
    if [ "$transport" = "tls" ]; then
      if printf '' | timeout 1 openssl s_client -connect "127.0.0.1:$port" -servername localhost -alpn http/1.1 >/dev/null 2>&1; then
        return 0
      fi
    elif php -r '
      set_error_handler(static fn(): bool => true);
      $socket = stream_socket_client("tcp://127.0.0.1:" . $argv[1], $errno, $error, 0.05);
      restore_error_handler();
      if (!is_resource($socket)) {
          throw new RuntimeException("not ready");
      }
      fclose($socket);
    ' "$port" >/dev/null 2>&1; then
      return 0
    fi

    if ! kill -0 "$server_pid" 2>/dev/null; then
      cat "$log" >&2
      return 1
    fi
    sleep 0.02
  done

  cat "$log" >&2
  return 1
}

start_server() {
  local nodelay="$1"
  local payload="$2"
  local workers="$3"
  local label="$4"
  local port
  port="$(find_port)"

  (
    cd "$runwire_root"
    exec php -d opcache.enable_cli=1 "$control_root/benchmarks/http12_lab_server.php"       "$port" "$payload" "$workers" "$nodelay" "$certificate" "$private_key"
  ) >"$output_dir/$label-server.log" 2>&1 &
  server_pid=$!
  wait_ready "$port" "$output_dir/$label-server.log"
  ACTIVE_PORT="$port"
  CURRENT_PAYLOAD="$payload"
}

stop_server() {
  cleanup
  server_pid=""
}

run_case() {
  local label="$1"
  local concurrency="$2"
  local warmup="$3"
  local duration="$4"

  RUNWIRE_RUNTIME_VERSION="2.0-$backend"   RUNWIRE_RUNTIME_BUILD="$(git -C "$runwire_root" rev-parse HEAD)"   RUNWIRE_INSTRUMENTATION="protocol-playground-$backend-$transport"   RUNWIRE_BENCH_TCP_NODELAY=0   RUNWIRE_BENCH_TLS="$([ "$transport" = "tls" ] && echo 1 || echo 0)"     php -d opcache.enable_cli=1 "$control_root/benchmarks/http1_sustained_bench.php"       "$ACTIVE_PORT" "$concurrency" "$warmup" "$duration" "$server_pid"       > "$output_dir/$label.json"

  jq -e '
    .correctness_passed == true
    and .errors_total == 0
    and .timeouts_total == 0
    and .validation_failures == 0
  ' "$output_dir/$label.json" >/dev/null
}

for nodelay in 0 1; do
  for payload in 2 1024 16384; do
    prefix="$transport-$backend-nodelay$nodelay-payload$payload"
    start_server "$nodelay" "$payload" 1 "$prefix"

    for concurrency in 1 16 64 256; do
      run_case "$prefix-c$concurrency" "$concurrency" 1 3
    done

    stop_server
  done
done

if [ "$transport" = "plain" ] && [ "$backend" = "event" ]; then
  for workers in 1 2 4; do
    prefix="scaling-workers$workers"
    start_server 1 2 "$workers" "$prefix"
    run_case "$prefix-c256" 256 1 5
    stop_server
  done
fi

jq -s '.' "$output_dir"/*.json > "$output_dir/summary.json"
