#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 6 ]; then
  echo "Usage: $0 <control-root> <coalesce-root> <certificate> <private-key> <output-dir> <duration>" >&2
  exit 2
fi

control_root="$(cd "$1" && pwd)"
coalesce_root="$(cd "$2" && pwd)"
certificate="$3"
private_key="$4"
output_dir="$5"
duration="$6"
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

  for _ in $(seq 1 250); do
    if printf '' | timeout 1 openssl s_client       -connect "127.0.0.1:$port"       -servername localhost       -alpn h2 2>&1 | grep -q 'ALPN protocol: h2'; then
      return 0
    fi

    if ! kill -0 "$server_pid" 2>/dev/null; then
      cat "$log" >&2
      return 1
    fi
    sleep 0.02
  done

  echo "HTTP/2 validation server did not become ALPN-ready." >&2
  cat "$log" >&2
  return 1
}

start_server() {
  local runtime_root="$1"
  local coalesce="$2"
  local nodelay="$3"
  local payload="$4"
  local label="$5"
  local port

  port="$(find_port)"
  CURRENT_SERVER_LOG="$output_dir/$label-server.log"

  RUNWIRE_BENCH_RUNTIME_ROOT="$runtime_root"   RUNWIRE_BENCH_H2_COALESCE="$coalesce"   RUNWIRE_H2_MAX_STREAMS_PER_CONNECTION=1000000     php -d opcache.enable_cli=1       "$control_root/benchmarks/http12_lab_server.php"       "$port" "$payload" 1 "$nodelay" "$certificate" "$private_key"       >"$CURRENT_SERVER_LOG" 2>&1 &
  server_pid=$!

  wait_ready "$port" "$CURRENT_SERVER_LOG"
  ACTIVE_PORT="$port"
}

stop_server() {
  cleanup
  server_pid=""
}

measure() {
  local mode="$1"
  local payload="$2"
  local streams="$3"
  local label="$mode-payload$payload-m$streams"
  local url="https://127.0.0.1:$ACTIVE_PORT/benchmark"

  if ! h2load -n 256 -c 1 -m "$streams" -t 1 "$url"     >"$output_dir/$label-warmup.raw" 2>&1; then
    cat "$output_dir/$label-warmup.raw" >&2
    cat "$CURRENT_SERVER_LOG" >&2
    return 1
  fi

  if ! h2load -n 1000000 -c 1 -m "$streams" -t 1 -D "$duration" "$url"     >"$output_dir/$label.raw" 2>&1; then
    cat "$output_dir/$label.raw" >&2
    cat "$CURRENT_SERVER_LOG" >&2
    return 1
  fi

  php "$control_root/benchmarks/h2load_parse.php"     "$output_dir/$label.raw"     "$([ "$mode" = "nodelay" ] || [ "$mode" = "both" ] && echo 1 || echo 0)"     "$payload" "$streams" 1 "$label"     >"$output_dir/$label.json"

  if ! jq -e '.correctness_passed == true and .requests_failed == 0 and .requests_errored == 0 and .requests_timed_out == 0'     "$output_dir/$label.json" >/dev/null; then
    echo "--- HTTP/2 validation correctness failure: $label ---" >&2
    cat "$output_dir/$label.json" >&2
    cat "$output_dir/$label.raw" >&2
    cat "$CURRENT_SERVER_LOG" >&2
    return 1
  fi
}

for payload in 2 1024 16384; do
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

    prefix="$mode-payload$payload"
    start_server "$runtime_root" "$coalesce" "$nodelay" "$payload" "$prefix"

    for streams in 1 8 32 100; do
      measure "$mode" "$payload" "$streams"
    done

    stop_server
  done
done

jq -s '.' "$output_dir"/*.json > "$output_dir/summary.json"
