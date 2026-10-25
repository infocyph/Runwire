#!/usr/bin/env bash
set -euo pipefail
output="$1"
python="$RUNWIRE_AIOQUIC_PYTHON"
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
if (!is_string($name) || !str_contains($name, ":")) {
    throw new RuntimeException("Unable to allocate independent UDP test port.");
}
echo substr(strrchr($name, ":"), 1);
')"
"$python" -u tests/interop/aioquic_quic_echo_server.py \
    "$port" "$tmpdir/cert.pem" "$tmpdir/key.pem" "$tmpdir/ready" "$tmpdir/uni.receipt" \
    --impair-post-handshake >"$tmpdir/peer.log" 2>&1 &
server_pid=$!
for attempt in $(seq 1 100); do
    if [ -s "$tmpdir/ready" ]; then break; fi
    if ! kill -0 "$server_pid" 2>/dev/null; then
        cat "$tmpdir/peer.log"
        exit 1
    fi
    sleep 0.03
done
test -s "$tmpdir/ready" || { cat "$tmpdir/peer.log"; exit 1; }
mkdir -p "$(dirname "$output")"
if ! php tests/fixtures/quic_session_echo_client.php \
    127.0.0.1 "$port" "$tmpdir/cert.pem" "$tmpdir/uni.receipt" > "$output"; then
    cat "$tmpdir/peer.log"
    exit 1
fi
jq -e '.verified == true and .concurrent_bidirectional == true
    and .native_reset_storm_count == 6
    and .verified_post_reset_bidi_streams == 2
    and .unidirectional_streams_delivered == 1' "$output" >/dev/null
jq -e '.fault_profile == "post-handshake-udp-loss-reorder"
    and .verified_handshake == true and .post_handshake_datagrams > 19
    and .dropped_datagrams >= 1 and .delayed_datagrams >= 1' \
    "$tmpdir/uni.receipt.fault.json" >/dev/null
peer_output="$(dirname "$output")/$(basename "$output" .json)-peer.json"
cp "$tmpdir/uni.receipt.fault.json" "$peer_output"
