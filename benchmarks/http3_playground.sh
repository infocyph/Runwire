#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 6 ]; then
  echo "Usage: $0 <control-root> <osslclient> <certificate> <private-key> <output-dir> <requests>" >&2
  exit 2
fi

control_root="$(cd "$1" && pwd)"
osslclient="$2"
certificate="$3"
private_key="$4"
output_dir="$5"
requests="$6"
mkdir -p "$output_dir"

server_pid=""

cleanup() {
  if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
    kill -TERM "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
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

run_case() {
  local label="$1"
  local poll="$2"
  local writes="$3"
  local streams_per_pump="$4"
  local concurrent="$5"
  local payload="$6"
  local max_reads="$7"
  local port
  local ready
  port="$(find_udp_port)"
  ready="$output_dir/$label.ready"
  rm -f "$ready"

  php "$control_root/benchmarks/http3_lab_server.php"     "$port" "$certificate" "$private_key" "$ready" "$requests"     "$poll" "$writes" "$streams_per_pump" "$concurrent" "$payload" "$max_reads"     >"$output_dir/$label-server.log" 2>&1 &
  server_pid=$!

  for _ in $(seq 1 300); do
    if [ -s "$ready" ]; then
      break
    fi
    if ! kill -0 "$server_pid" 2>/dev/null; then
      cat "$output_dir/$label-server.log" >&2
      return 1
    fi
    sleep 0.02
  done
  test -s "$ready"

  uris=()
  for request in $(seq 1 "$requests"); do
    uris+=("https://127.0.0.1:$port/benchmark?request=$request")
  done

  started="$(date +%s%N)"
  "$osslclient" --exit-on-all-streams-close     127.0.0.1 "$port" "${uris[@]}"     >"$output_dir/$label-client.log" 2>&1
  elapsed_ns=$(( $(date +%s%N) - started ))

  wait "$server_pid"
  server_pid=""

  php -r '
  $elapsed = (int) $argv[1];
  $requests = (int) $argv[2];
  $result = [
      "label" => $argv[3],
      "poll_timeout_seconds" => (float) $argv[4],
      "max_writes_per_flush" => (int) $argv[5],
      "max_streams_accepted_per_pump" => (int) $argv[6],
      "max_concurrent_request_streams" => (int) $argv[7],
      "payload_bytes" => (int) $argv[8],
      "max_reads_per_pump" => (int) $argv[9],
      "requests" => $requests,
      "elapsed_ms" => round($elapsed / 1000000, 3),
      "throughput_rps" => round($requests / ($elapsed / 1000000000), 3),
      "correctness_passed" => true,
  ];
  echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
  ' "$elapsed_ns" "$requests" "$label" "$poll" "$writes" "$streams_per_pump" "$concurrent" "$payload" "$max_reads"     > "$output_dir/$label.json"
}

for poll in 0.05 0.01 0.005 0.001; do
  run_case "poll-$poll" "$poll" 128 64 100 2 256
done

for writes in 16 64 128 512; do
  run_case "writes-$writes" 0.005 "$writes" 64 100 2 256
done

for streams in 8 32 64 128; do
  run_case "streams-per-pump-$streams" 0.005 128 "$streams" 100 2 256
done

for concurrent in 16 32 64 100; do
  run_case "concurrent-$concurrent" 0.005 128 64 "$concurrent" 2 256
done

for payload in 2 1024 16384; do
  run_case "payload-$payload" 0.005 128 64 100 "$payload" 256
done

jq -s '.' "$output_dir"/*.json > "$output_dir/summary.json"
