#!/usr/bin/env bash
set -euo pipefail

python_bin="${RUNWIRE_AIOQUIC_PYTHON:-/tmp/runwire-aioquic/bin/python}"
test -x "$python_bin" || { echo 'aioquic client environment unavailable.' >&2; exit 1; }
workspace="$(mktemp -d)"
server_pid=""

cleanup() {
  if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
    kill -TERM "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
  rm -rf "$workspace"
}
trap cleanup EXIT

openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=localhost' \
  -keyout "$workspace/key.pem" -out "$workspace/cert.pem" >/dev/null 2>&1

port="$(php -r '
$server = stream_socket_server("tcp://127.0.0.1:0", $code, $error);
if (!is_resource($server)) { throw new RuntimeException($error); }
$address = stream_socket_get_name($server, false);
fclose($server);
if (!is_string($address) || !str_contains($address, ":")) {
    throw new RuntimeException("Cannot allocate loopback port.");
}
echo substr(strrchr($address, ":"), 1);
')"

ready="$workspace/ready"
php -d opcache.enable_cli=1 benchmarks/adaptive_http3_server.php \
  "$port" "$workspace/cert.pem" "$workspace/key.pem" "$ready" fixed 65536 \
  >"$workspace/server.log" 2>&1 &
server_pid=$!

for attempt in $(seq 1 100); do
  if [ -f "$ready" ]; then
    break
  fi
  if ! kill -0 "$server_pid" 2>/dev/null; then
    cat "$workspace/server.log" >&2
    exit 1
  fi
  sleep 0.05
done
if [ ! -f "$ready" ]; then
  cat "$workspace/server.log" >&2
  echo 'Native H3 fixture failed to become ready.' >&2
  exit 1
fi

if ! "$python_bin" benchmarks/http3_fault_acceptance.py "$port"; then
  cat "$workspace/server.log" >&2
  exit 1
fi
