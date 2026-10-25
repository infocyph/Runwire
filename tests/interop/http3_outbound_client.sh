#!/usr/bin/env bash
set -euo pipefail
output="${1:?Expected outbound HTTP/3 evidence JSON file}"
python="${RUNWIRE_AIOQUIC_PYTHON:?Independent aioquic Python is required}"
test -x "$python"
tmpdir="$(mktemp -d)"
server_pid=""
cleanup() {
    if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
        kill -TERM "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    rm -rf "$tmpdir"
}
trap cleanup EXIT
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
    -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' \
    -keyout "$tmpdir/key.pem" -out "$tmpdir/cert.pem" >/dev/null 2>&1
port="$(php -r '
$udp = stream_socket_server("udp://127.0.0.1:0", $code, $error, STREAM_SERVER_BIND);
if (!is_resource($udp)) { throw new RuntimeException($error); }
$name = stream_socket_get_name($udp, false);
fclose($udp);
if (!is_string($name) || !str_contains($name, ":")) { throw new RuntimeException("Cannot allocate QUIC peer port."); }
echo substr(strrchr($name, ":"), 1);
')"
"$python" -u tests/interop/aioquic_outbound_h3_server.py "$port" \
    "$tmpdir/cert.pem" "$tmpdir/key.pem" "$tmpdir/ready" >"$tmpdir/peer.log" 2>&1 &
server_pid=$!
for attempt in $(seq 1 100); do
    if [ -s "$tmpdir/ready" ]; then break; fi
    if ! kill -0 "$server_pid" 2>/dev/null; then cat "$tmpdir/peer.log"; exit 1; fi
    sleep 0.03
done
test -s "$tmpdir/ready" || { cat "$tmpdir/peer.log"; exit 1; }
mkdir -p "$(dirname "$output")"
if ! php tests/fixtures/http3_outbound_client.php "$port" "$tmpdir/cert.pem" >"$output"; then
    cat "$tmpdir/peer.log"
    exit 1
fi
jq -e '.verified and .http3_outbound_independent_peer and .tls_alpn == "h3" and .response_status == 200 and .exact_body and .finished_exactly_once and .loop_resources_released' "$output" >/dev/null
