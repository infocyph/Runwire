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
    if printf '' | timeout 1 openssl s_client       -connect "127.0.0.1:$port" -servername localhost -alpn h2 2>/dev/null       | grep -q 'ALPN protocol: h2'; then
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
  local label="$3"
  local port
  port="$(find_port)"

  (
    cd "$runwire_root"
    exec php -d opcache.enable_cli=1 "$control_root/benchmarks/http12_lab_server.php"       "$port" "$payload" 1 "$nodelay" "$certificate" "$private_key"
  ) >"$output_dir/$label-server.log" 2>&1 &
  server_pid=$!
  wait_ready "$port" "$output_dir/$label-server.log"
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

  h2load -n 256 -c "$connections" -m "$streams" -t 1 "$url" >/dev/null 2>&1
  h2load -n 1000000 -c "$connections" -m "$streams" -t 1 -D 5 "$url"     > "$output_dir/$label.raw" 2>&1

  php "$control_root/benchmarks/h2load_parse.php"     "$output_dir/$label.raw" "$nodelay" "$payload" "$streams" "$connections" "$label"     > "$output_dir/$label.json"

  jq -e '.correctness_passed == true and .requests_failed == 0' "$output_dir/$label.json" >/dev/null
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

jq -s '.' "$output_dir"/*.json > "$output_dir/summary.json"
