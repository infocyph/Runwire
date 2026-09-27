#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 5 ]; then
  echo "Usage: $0 <control-root> <workerman-root> <react-root> <amp-root> <output-dir>" >&2
  exit 2
fi

control_root="$(cd "$1" && pwd)"
workerman_root="$(cd "$2" && pwd)"
react_root="$(cd "$3" && pwd)"
amp_root="$(cd "$4" && pwd)"
output_dir="$5"
mkdir -p "$output_dir"

server_pid=""
server_log=""

cleanup() {
  if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
    kill -TERM "$server_pid" 2>/dev/null || true
    for _ in $(seq 1 100); do
      if ! kill -0 "$server_pid" 2>/dev/null; then
        break
      fi
      sleep 0.02
    done
    if kill -0 "$server_pid" 2>/dev/null; then
      kill -KILL "$server_pid" 2>/dev/null || true
    fi
    wait "$server_pid" 2>/dev/null || true
  fi
  server_pid=""
}
trap cleanup EXIT

find_port() {
  php -r '
  $server = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
  if (!is_resource($server)) {
      throw new RuntimeException($error !== "" ? $error : "Unable to allocate peer benchmark port.");
  }
  $name = stream_socket_get_name($server, false);
  fclose($server);
  if (!is_string($name) || !str_contains($name, ":")) {
      throw new RuntimeException("Unable to resolve peer benchmark port.");
  }
  echo substr(strrchr($name, ":"), 1);
  '
}

wait_ready() {
  local port="$1"
  local log="$2"

  for _ in $(seq 1 300); do
    if php -r '
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
      echo "--- peer server exited before readiness ---" >&2
      cat "$log" >&2
      return 1
    fi
    sleep 0.02
  done

  echo "--- peer server readiness timed out ---" >&2
  cat "$log" >&2
  return 1
}

start_server() {
  local peer="$1"
  local nodelay="$2"
  local port
  port="$(find_port)"
  server_log="$output_dir/$peer-server.log"

  case "$peer" in
    runwire)
      (
        cd "$control_root"
        exec php -d opcache.enable_cli=1 benchmarks/http12_lab_server.php "$port" 2 1 "$nodelay" - -
      ) >"$server_log" 2>&1 &
      ;;
    workerman)
      (
        cd "$control_root"
        exec env PEER_ROOT="$workerman_root" PEER_PORT="$port"           php -d opcache.enable_cli=1 benchmarks/peers/workerman_http1_server.php start
      ) >"$server_log" 2>&1 &
      ;;
    react)
      (
        cd "$control_root"
        exec env PEER_ROOT="$react_root" PEER_PORT="$port" PEER_NODELAY="$nodelay"           php -d opcache.enable_cli=1 benchmarks/peers/react_http1_server.php
      ) >"$server_log" 2>&1 &
      ;;
    amp)
      (
        cd "$control_root"
        exec env PEER_ROOT="$amp_root" PEER_PORT="$port" PEER_NODELAY="$nodelay"           php -d opcache.enable_cli=1 benchmarks/peers/amp_http1_server.php
      ) >"$server_log" 2>&1 &
      ;;
    *)
      echo "Unknown peer: $peer" >&2
      exit 2
      ;;
  esac

  server_pid=$!
  wait_ready "$port" "$server_log"
  ACTIVE_PORT="$port"
}

peer_build() {
  case "$1" in
    runwire) git -C "$control_root" rev-parse HEAD ;;
    workerman) git -C "$workerman_root" rev-parse HEAD ;;
    react) git -C "$react_root" rev-parse HEAD ;;
    amp) git -C "$amp_root" rev-parse HEAD ;;
  esac
}

run_mode() {
  local peer="$1"
  local mode="$2"
  local nodelay="$3"
  local label="$peer-$mode"
  local build
  build="$(peer_build "$peer")"

  start_server "$peer" "$nodelay"
  results=()

  for trial in 1 2 3 4 5; do
    warmup=0
    if [ "$trial" -eq 1 ]; then
      warmup=2
    fi

    result="$output_dir/$label-trial-$trial.json"
    RUNWIRE_BENCH_RUNTIME="$peer"     RUNWIRE_RUNTIME_VERSION="$mode"     RUNWIRE_RUNTIME_BUILD="$build"     RUNWIRE_INSTRUMENTATION="peer-http1-crossmatch"     RUNWIRE_OPCACHE="on"     RUNWIRE_BENCH_EXPECTED_BODY="xx"       php -d opcache.enable_cli=1 "$control_root/benchmarks/http1_sustained_bench.php"         "$ACTIVE_PORT" 16 "$warmup" 5 "$server_pid" > "$result"

    jq -e '
      .correctness_passed == true
      and .successful_requests == .requests_total
      and .errors_total == 0
      and .timeouts_total == 0
      and .validation_failures == 0
    ' "$result" >/dev/null
    results+=("$result")
  done

  php "$control_root/benchmarks/sustained_summary.php" "${results[@]}"     > "$output_dir/$label-summary.json"

  cleanup
}

run_mode runwire default 0
run_mode runwire nodelay 1
run_mode workerman default 0
run_mode react default 0
run_mode react nodelay 1
run_mode amp default 0
run_mode amp nodelay 1

jq -n   --slurpfile rd "$output_dir/runwire-default-summary.json"   --slurpfile rn "$output_dir/runwire-nodelay-summary.json"   --slurpfile wd "$output_dir/workerman-default-summary.json"   --slurpfile xd "$output_dir/react-default-summary.json"   --slurpfile xn "$output_dir/react-nodelay-summary.json"   --slurpfile ad "$output_dir/amp-default-summary.json"   --slurpfile an "$output_dir/amp-nodelay-summary.json"   '{
    runwire_default: $rd[0],
    runwire_nodelay: $rn[0],
    workerman_default: $wd[0],
    react_default: $xd[0],
    react_nodelay: $xn[0],
    amp_default: $ad[0],
    amp_nodelay: $an[0]
  }' > "$output_dir/crossmatch-summary.json"

cat "$output_dir/crossmatch-summary.json"
