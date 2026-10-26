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
echo "C1 outbound H3: independent one-shot control/QPACK acceptance"
if ! php -d display_errors=stderr -d log_errors=1 tests/fixtures/http3_outbound_client.php "$port" "$tmpdir/cert.pem" >"$output" 2>"$tmpdir/one-shot.err"; then
    cat "$tmpdir/one-shot.err"
    cat "$tmpdir/peer.log"
    exit 1
fi
jq -e '.verified and .http3_outbound_independent_peer and .tls_alpn == "h3" and .response_status == 200 and .exact_body and .finished_exactly_once and .loop_resources_released' "$output" >/dev/null
echo "C1 outbound H3: independent upload/trailer acceptance"
if ! php -d display_errors=stderr -d log_errors=1 tests/fixtures/http3_outbound_upload.php "$port" "$tmpdir/cert.pem" >"$output.upload" 2>"$tmpdir/upload.err"; then
    cat "$tmpdir/upload.err" "$tmpdir/peer.log"
    exit 1
fi
jq -e '.verified and .independent_upload and .trailing_headers and .exact_response and .cleanup' "$output.upload" >/dev/null
echo "C1 outbound H3: four simultaneous QUIC stream acceptance"
if ! php -d display_errors=stderr -d log_errors=1 tests/fixtures/http3_outbound_multiplex.php "$port" "$tmpdir/cert.pem" >"$output.multiplex" 2>"$tmpdir/multiplex.err"; then
    cat "$tmpdir/multiplex.err" "$tmpdir/peer.log"
    exit 1
fi
jq -e '.verified and .single_quic_session and .concurrent_requests == 4
    and .responses_completed == 4 and .exact_responses and .cleanup' "$output.multiplex" >/dev/null
if [ "$#" -eq 2 ]; then
    profile_output="$2"
    php benchmarks/u05_native_h3_transport_profile.php "$port" "$tmpdir/cert.pem" 16 >"$profile_output"
    jq -e '.schema == 1 and .real_network_io == true and .verified_tls_alpn == true
        and .correctness_passed == true and .concurrency == 1
        and .requests_total == 16 and .successful_requests == 16 and .errors_total == 0
        and .successful_rps > 0 and .p99_ms > 0 and .peak_memory_bytes > 0
        and .parent_cpu_ms > 0 and .full_end_to_end_certification == false' "$profile_output" >/dev/null
fi
