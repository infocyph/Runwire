#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 7 ]; then
  echo "Usage: $0 <control-root> <osslclient> <certificate> <private-key> <output-dir> <requests> <trials>" >&2
  exit 2
fi

control_root="$(cd "$1" && pwd)"
osslclient="$2"
certificate="$3"
private_key="$4"
output_dir="$5"
requests="$6"
trials="$7"
mkdir -p "$output_dir"

server_pid=""

cleanup() {
  if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
    kill -TERM "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
  server_pid=""
}
trap cleanup EXIT

find_udp_port() {
  php -r '
  $server = stream_socket_server("udp://127.0.0.1:0", $errno, $error, STREAM_SERVER_BIND);
  if (!is_resource($server)) {
      throw new RuntimeException($error !== "" ? $error : "Unable to allocate UDP port.");
  }
  $name = stream_socket_get_name($server, false);
  fclose($server);
  echo substr(strrchr((string) $name, ":"), 1);
  '
}

run_trial() {
  local label="$1"
  local trial="$2"
  local poll="$3"
  local writes="$4"
  local streams_per_pump="$5"
  local concurrent="$6"
  local payload="$7"
  local max_reads="$8"
  local port
  local ready
  local server_log
  local client_log
  local uri
  local started
  local elapsed_ns

  port="$(find_udp_port)"
  ready="$output_dir/$label-trial$trial.ready"
  server_log="$output_dir/$label-trial$trial-server.log"
  client_log="$output_dir/$label-trial$trial-client.log"
  uri="https://127.0.0.1:$port/benchmark?request=1"
  rm -f "$ready"

  RUNWIRE_H3_MAX_STREAMS_PER_CONNECTION=1000000     php "$control_root/benchmarks/http3_lab_server.php"       "$port" "$certificate" "$private_key" "$ready" "$requests"       "$poll" "$writes" "$streams_per_pump" "$concurrent" "$payload" "$max_reads"       >"$server_log" 2>&1 &
  server_pid=$!

  for _ in $(seq 1 300); do
    if [ -s "$ready" ]; then
      break
    fi
    if ! kill -0 "$server_pid" 2>/dev/null; then
      cat "$server_log" >&2
      return 1
    fi
    sleep 0.02
  done
  test -s "$ready"

  started="$(date +%s%N)"
  if ! "$osslclient" -q --exit-on-all-streams-close -n "$requests"     127.0.0.1 "$port" "$uri" >"$client_log" 2>&1; then
    cat "$client_log" >&2
    cat "$server_log" >&2
    return 1
  fi
  elapsed_ns=$(( $(date +%s%N) - started ))

  if ! wait "$server_pid"; then
    server_pid=""
    cat "$server_log" >&2
    return 1
  fi
  server_pid=""

  php -r '
  $elapsed = (int) $argv[1];
  $requests = (int) $argv[2];
  $result = [
      "label" => $argv[3],
      "trial" => (int) $argv[4],
      "poll_timeout_seconds" => (float) $argv[5],
      "max_writes_per_flush" => (int) $argv[6],
      "max_streams_accepted_per_pump" => (int) $argv[7],
      "max_concurrent_request_streams" => (int) $argv[8],
      "payload_bytes" => (int) $argv[9],
      "max_reads_per_pump" => (int) $argv[10],
      "requests" => $requests,
      "elapsed_ms" => round($elapsed / 1000000, 3),
      "throughput_rps" => round($requests / ($elapsed / 1000000000), 3),
      "correctness_passed" => true,
  ];
  echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
  ' "$elapsed_ns" "$requests" "$label" "$trial" "$poll" "$writes" "$streams_per_pump" "$concurrent" "$payload" "$max_reads"     >"$output_dir/$label-trial$trial.json"
}

run_variant() {
  local label="$1"
  local poll="$2"
  local writes="$3"
  local streams_per_pump="$4"
  local concurrent="$5"
  local payload="$6"
  local max_reads="$7"
  local files=()

  for trial in $(seq 1 "$trials"); do
    run_trial "$label" "$trial" "$poll" "$writes" "$streams_per_pump" "$concurrent" "$payload" "$max_reads"
    files+=("$output_dir/$label-trial$trial.json")
  done

  php "$control_root/benchmarks/rps_trial_summary.php" "${files[@]}"     >"$output_dir/$label-summary.json"
}

run_variant baseline       0.05  128  64 100     2 256
run_variant poll-0.001     0.001 128  64 100     2 256
run_variant poll-0.01      0.01  128  64 100     2 256
run_variant writes-64      0.05   64  64 100     2 256
run_variant writes-512     0.05  512  64 100     2 256
run_variant streams-128    0.05  128 128 100     2 256
run_variant concurrent-32  0.05  128  64  32     2 256
run_variant payload-16384  0.05  128  64 100 16384 256

jq -s '.' "$output_dir"/*-summary.json >"$output_dir/summary.json"
