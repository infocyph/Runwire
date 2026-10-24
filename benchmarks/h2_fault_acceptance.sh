#!/usr/bin/env bash
set -euo pipefail

python_bin="${RUNWIRE_H2_PYTHON:-/tmp/runwire-h2/bin/python}"
if [ ! -x "$python_bin" ]; then
  echo "Independent h2 Python environment is required." >&2
  exit 1
fi

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
    throw new RuntimeException("Cannot allocate a bound loopback port.");
}
echo substr(strrchr($address, ":"), 1);
')"

php -d opcache.enable_cli=1 benchmarks/adaptive_http_server.php \
  h2 "$port" fixed 1024 "$workspace/cert.pem" "$workspace/key.pem" \
  >"$workspace/server.log" 2>&1 &
server_pid=$!

ready=0
for attempt in $(seq 1 100); do
  if ! kill -0 "$server_pid" 2>/dev/null; then
    cat "$workspace/server.log" >&2
    exit 1
  fi
  if php -r '$stream = @stream_socket_client("tcp://127.0.0.1:" . $argv[1], $code, $error, 0.05); if (is_resource($stream)) { fclose($stream); } else { throw new RuntimeException($error); }' "$port" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 0.05
done

if [ "$ready" -ne 1 ]; then
  cat "$workspace/server.log" >&2
  echo "H2 fixture did not become ready." >&2
  exit 1
fi

"$python_bin" benchmarks/h2_fault_acceptance.py "$port"
