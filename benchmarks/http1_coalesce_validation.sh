#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 7 ]; then
  echo "Usage: $0 <control-root> <coalesce-root> <certificate> <private-key> <output-dir> <duration> <warmup>" >&2
  exit 2
fi

control_root="$(cd "$1" && pwd)"
coalesce_root="$(cd "$2" && pwd)"
certificate="$3"
private_key="$4"
output_dir="$5"
duration="$6"
warmup="$7"
mkdir -p "$output_dir"

server_pid=""
ACTIVE_PORT=""
CURRENT_SERVER_LOG=""
CURRENT_PAYLOAD=""

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
  local transport="$2"
  local log="$3"

  for _ in $(seq 1 250); do
    if [ "$transport" = "tls" ]; then
      if printf '' | timeout 1 openssl s_client         -connect "127.0.0.1:$port"         -servername localhost         -alpn http/1.1 >/dev/null 2>&1; then
        return 0
      fi
    elif php -r '
      set_error_handler(static fn(): bool => true);
      $socket = stream_socket_client("tcp://127.0.0.1:" . $argv[1], $errno, $error, 0.05);
      restore_error_handler();
      if (!is_resource($socket)) {
          exit(1);
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

  echo "HTTP/1 validation server did not become ready." >&2
  cat "$log" >&2
  return 1
}

start_server() {
  local runtime_root="$1"
  local coalesce="$2"
  local nodelay="$3"
  local payload="$4"
  local transport="$5"
  local label="$6"
  local port
  local cert_arg="-"
  local key_arg="-"

  if [ "$transport" = "tls" ]; then
    cert_arg="$certificate"
    key_arg="$private_key"
  fi

  port="$(find_port)"
  CURRENT_PAYLOAD="$payload"
  CURRENT_SERVER_LOG="$output_dir/$label-server.log"

  RUNWIRE_BENCH_RUNTIME_ROOT="$runtime_root"   RUNWIRE_BENCH_H1_COALESCE="$coalesce"     php -d opcache.enable_cli=1       "$control_root/benchmarks/http12_lab_server.php"       "$port" "$payload" 1 "$nodelay" "$cert_arg" "$key_arg"       >"$CURRENT_SERVER_LOG" 2>&1 &
  server_pid=$!

  wait_ready "$port" "$transport" "$CURRENT_SERVER_LOG"
  ACTIVE_PORT="$port"
}

stop_server() {
  cleanup
  server_pid=""
}

run_case() {
  local mode="$1"
  local transport="$2"
  local payload="$3"
  local concurrency="$4"
  local expected_body
  local label

  expected_body="$(php -r 'echo str_repeat("x", (int) $argv[1]);' "$payload")"
  label="$mode-$transport-payload$payload-c$concurrency"

  if ! RUNWIRE_RUNTIME_VERSION="2.0-$mode"     RUNWIRE_RUNTIME_BUILD="$(git -C "$control_root" rev-parse HEAD)"     RUNWIRE_INSTRUMENTATION="h1-write-shape-$mode-$transport"     RUNWIRE_BENCH_TCP_NODELAY=0     RUNWIRE_BENCH_TLS="$([ "$transport" = "tls" ] && echo 1 || echo 0)"     RUNWIRE_BENCH_EXPECTED_BODY="$expected_body"       php -d opcache.enable_cli=1         "$control_root/benchmarks/http1_sustained_bench.php"         "$ACTIVE_PORT" "$concurrency" "$warmup" "$duration" "$server_pid"         >"$output_dir/$label.json"         2>"$output_dir/$label-client.log"; then
    echo "--- HTTP/1 validation client failure: $label ---" >&2
    cat "$output_dir/$label-client.log" >&2
    echo "--- server ---" >&2
    cat "$CURRENT_SERVER_LOG" >&2
    return 1
  fi

  jq -e '
    .correctness_passed == true
    and .successful_requests == .requests_total
    and .errors_total == 0
    and .timeouts_total == 0
    and .validation_failures == 0
  ' "$output_dir/$label.json" >/dev/null
}

for transport in plain tls; do
  for payload in 2 16384; do
    for mode in default nodelay coalesce both; do
      runtime_root="$control_root"
      coalesce=0
      nodelay=0

      case "$mode" in
        default)
          ;;
        nodelay)
          nodelay=1
          ;;
        coalesce)
          runtime_root="$coalesce_root"
          coalesce=1
          ;;
        both)
          runtime_root="$coalesce_root"
          coalesce=1
          nodelay=1
          ;;
      esac

      prefix="$mode-$transport-payload$payload"
      start_server "$runtime_root" "$coalesce" "$nodelay" "$payload" "$transport" "$prefix"

      for concurrency in 1 16 64 256; do
        run_case "$mode" "$transport" "$payload" "$concurrency"
      done

      stop_server
    done
  done
done

jq -s '.' "$output_dir"/*.json > "$output_dir/summary.json"
