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

cp "$control_root/benchmarks/http1_nodelay_server.php" "$v1_root/benchmarks/http1_nodelay_server.php"
cp "$control_root/benchmarks/http1_nodelay_server.php" "$v2_root/benchmarks/http1_nodelay_server.php"

find_port() {
  php -r '
$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
if (!is_resource($server)) {
    throw new RuntimeException($error !== "" ? $error : "Unable to allocate diagnostic port.");
}
$name = stream_socket_get_name($server, false);
fclose($server);
if (!is_string($name) || !str_contains($name, ":")) {
    throw new RuntimeException("Unable to resolve diagnostic port.");
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
set_error_handler(static fn(): bool => true);
$socket = stream_socket_client("tcp://127.0.0.1:" . $argv[1], $errno, $error, 0.05);
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

  echo "Diagnostic server on port $port did not become ready." >&2
  cat "$log" >&2
  return 1
}

stop_pid() {
  local pid="$1"
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
}

v1_sha="$(git -C "$v1_root" rev-parse HEAD)"
v2_sha="$(git -C "$v2_root" rev-parse HEAD)"
v1_pid=""
v2_pid=""
trap 'stop_pid "$v1_pid"; stop_pid "$v2_pid"' EXIT

run_mode() {
  local mode="$1"
  local server_nodelay="$2"
  local client_nodelay="$3"
  local v1_port
  local v2_port
  v1_port="$(find_port)"
  v2_port="$(find_port)"

  RUNWIRE_SERVER_TCP_NODELAY="$server_nodelay" php -d opcache.enable_cli=1     "$v1_root/benchmarks/http1_nodelay_server.php" "$v1_port"     >"$output_dir/$mode-v1-server.log" 2>&1 &
  v1_pid=$!

  RUNWIRE_SERVER_TCP_NODELAY="$server_nodelay" php -d opcache.enable_cli=1     "$v2_root/benchmarks/http1_nodelay_server.php" "$v2_port"     >"$output_dir/$mode-v2-server.log" 2>&1 &
  v2_pid=$!

  wait_ready "$v1_port" "$v1_pid" "$output_dir/$mode-v1-server.log"
  wait_ready "$v2_port" "$v2_pid" "$output_dir/$mode-v2-server.log"

  v1_results=()
  v2_results=()
  for trial in 1 2 3 4 5; do
    warmup=0
    if [ "$trial" -eq 1 ]; then
      warmup=2
    fi

    v1_result="$output_dir/$mode-v1-trial-$trial.json"
    v2_result="$output_dir/$mode-v2-trial-$trial.json"

    run_one() {
      local version="$1"
      local build="$2"
      local port="$3"
      local pid="$4"
      local result="$5"

      RUNWIRE_RUNTIME_VERSION="$version"       RUNWIRE_RUNTIME_BUILD="$build"       RUNWIRE_INSTRUMENTATION="tcp-nodelay-$mode"       RUNWIRE_BENCH_TCP_NODELAY="$client_nodelay"         php -d opcache.enable_cli=1 "$control_root/benchmarks/http1_sustained_bench.php"           "$port" 16 "$warmup" 5 "$pid" > "$result"

      jq -e '
        .correctness_passed == true
        and .successful_requests == .requests_total
        and .errors_total == 0
        and .timeouts_total == 0
        and .validation_failures == 0
      ' "$result" >/dev/null
    }

    if [ $((trial % 2)) -eq 1 ]; then
      run_one "1.0" "$v1_sha" "$v1_port" "$v1_pid" "$v1_result"
      run_one "2.0" "$v2_sha" "$v2_port" "$v2_pid" "$v2_result"
    else
      run_one "2.0" "$v2_sha" "$v2_port" "$v2_pid" "$v2_result"
      run_one "1.0" "$v1_sha" "$v1_port" "$v1_pid" "$v1_result"
    fi

    v1_results+=("$v1_result")
    v2_results+=("$v2_result")
  done

  php "$control_root/benchmarks/sustained_summary.php" "${v1_results[@]}"     > "$output_dir/$mode-v1-summary.json"
  php "$control_root/benchmarks/sustained_summary.php" "${v2_results[@]}"     > "$output_dir/$mode-v2-summary.json"

  stop_pid "$v1_pid"
  stop_pid "$v2_pid"
  v1_pid=""
  v2_pid=""
}

run_mode baseline 0 0
run_mode client-nodelay 0 1
run_mode server-nodelay 1 0
run_mode both-nodelay 1 1

jq -n   --slurpfile b1 "$output_dir/baseline-v1-summary.json"   --slurpfile b2 "$output_dir/baseline-v2-summary.json"   --slurpfile c1 "$output_dir/client-nodelay-v1-summary.json"   --slurpfile c2 "$output_dir/client-nodelay-v2-summary.json"   --slurpfile s1 "$output_dir/server-nodelay-v1-summary.json"   --slurpfile s2 "$output_dir/server-nodelay-v2-summary.json"   --slurpfile n1 "$output_dir/both-nodelay-v1-summary.json"   --slurpfile n2 "$output_dir/both-nodelay-v2-summary.json"   '{
    baseline: {v1: $b1[0], v2: $b2[0]},
    client_nodelay: {v1: $c1[0], v2: $c2[0]},
    server_nodelay: {v1: $s1[0], v2: $s2[0]},
    both_nodelay: {v1: $n1[0], v2: $n2[0]}
  }' > "$output_dir/diagnostic-summary.json"

cat "$output_dir/diagnostic-summary.json"
