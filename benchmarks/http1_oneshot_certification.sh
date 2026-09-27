#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 9 ]; then
  echo "Usage: $0 <control-root> <oneshot-root> <certificate> <private-key> <output-dir> <transport> <payload> <duration> <warmup>" >&2
  exit 2
fi

control_root="$(cd "$1" && pwd)"
oneshot_root="$(cd "$2" && pwd)"
certificate="$3"
private_key="$4"
output_dir="$5"
transport="$6"
payload="$7"
duration="$8"
warmup="$9"

if [ "$transport" != "plain" ] && [ "$transport" != "tls" ]; then
  echo "transport must be plain or tls" >&2
  exit 2
fi

mkdir -p "$output_dir"
server_pid=""
active_port=""
current_server_log=""
build="$(git -C "$control_root" rev-parse HEAD)"

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

  echo "HTTP/1 one-shot validation server did not become ready." >&2
  cat "$log" >&2
  return 1
}

start_server() {
  local mode="$1"
  local trial="$2"
  local concurrency="$3"
  local runtime_root="$control_root"
  local nodelay=0
  local cert_arg="-"
  local key_arg="-"
  local port

  case "$mode" in
    default)
      ;;
    nodelay)
      nodelay=1
      ;;
    oneshot)
      runtime_root="$oneshot_root"
      ;;
    both)
      runtime_root="$oneshot_root"
      nodelay=1
      ;;
    *)
      echo "Unknown mode: $mode" >&2
      return 2
      ;;
  esac

  if [ "$transport" = "tls" ]; then
    cert_arg="$certificate"
    key_arg="$private_key"
  fi

  port="$(find_port)"
  current_server_log="$output_dir/$mode-c$concurrency-trial$trial-server.log"

  RUNWIRE_BENCH_RUNTIME_ROOT="$runtime_root"     php -d opcache.enable_cli=1       "$control_root/benchmarks/http1_oneshot_lab_server.php"       "$port" "$payload" 1 "$nodelay" "$cert_arg" "$key_arg"       >"$current_server_log" 2>&1 &
  server_pid=$!

  wait_ready "$port" "$current_server_log"
  active_port="$port"
}

stop_server() {
  cleanup
  server_pid=""
}

run_case() {
  local mode="$1"
  local trial="$2"
  local concurrency="$3"
  local expected_body
  local label
  local output

  expected_body="$(php -r 'echo str_repeat("x", (int) $argv[1]);' "$payload")"
  label="$mode-$transport-payload$payload-c$concurrency"
  output="$output_dir/$label-trial$trial.json"

  if ! RUNWIRE_RUNTIME_VERSION="2.0-v1-oneshot-$mode"     RUNWIRE_RUNTIME_BUILD="$build"     RUNWIRE_INSTRUMENTATION="v1-oneshot-$transport-payload$payload-c$concurrency-$mode"     RUNWIRE_BENCH_TCP_NODELAY=0     RUNWIRE_BENCH_TLS="$([ "$transport" = "tls" ] && echo 1 || echo 0)"     RUNWIRE_BENCH_EXPECTED_BODY="$expected_body"       php -d opcache.enable_cli=1         "$control_root/benchmarks/http1_sustained_bench.php"         "$active_port" "$concurrency" "$warmup" "$duration" "$server_pid"         >"$output"         2>"$output_dir/$label-trial$trial-client.log"; then
    echo "--- HTTP/1 one-shot client failure: $label trial $trial ---" >&2
    cat "$output_dir/$label-trial$trial-client.log" >&2
    echo "--- server ---" >&2
    cat "$current_server_log" >&2
    return 1
  fi

  jq -e '
    .correctness_passed == true
    and .successful_requests == .requests_total
    and .errors_total == 0
    and .timeouts_total == 0
    and .validation_failures == 0
  ' "$output" >/dev/null
}

if [ "$transport" = "tls" ]; then
  concurrencies=(1 16 64 256)
else
  concurrencies=(16 64 256)
fi

for concurrency in "${concurrencies[@]}"; do
  for trial in 1 2 3 4 5; do
    if [ $((trial % 2)) -eq 1 ]; then
      modes=(default nodelay oneshot both)
    else
      modes=(both oneshot nodelay default)
    fi

    for mode in "${modes[@]}"; do
      start_server "$mode" "$trial" "$concurrency"
      run_case "$mode" "$trial" "$concurrency"
      stop_server
    done
  done

  for mode in default nodelay oneshot both; do
    files=()
    for trial in 1 2 3 4 5; do
      files+=("$output_dir/$mode-$transport-payload$payload-c$concurrency-trial$trial.json")
    done
    php "$control_root/benchmarks/sustained_summary.php" "${files[@]}"       >"$output_dir/$mode-$transport-payload$payload-c$concurrency-summary.json"
  done
done

jq -s '.' "$output_dir"/*-summary.json >"$output_dir/summary.json"
