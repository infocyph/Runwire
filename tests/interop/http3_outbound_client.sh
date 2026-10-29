#!/usr/bin/env bash
set -euo pipefail
output="${1:?Expected outbound HTTP/3 evidence JSON file}"
python="${RUNWIRE_AIOQUIC_PYTHON:?Independent aioquic Python is required}"
test -x "$python"
tmpdir="$(mktemp -d)"
server_pid=""
h12_pid=""
cleanup() {
    if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
        kill -TERM "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    if [ -n "$h12_pid" ] && kill -0 "$h12_pid" 2>/dev/null; then
        kill -TERM "$h12_pid" 2>/dev/null || true
        wait "$h12_pid" 2>/dev/null || true
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
    and .responses_completed == 4 and .exact_responses
    and .native_sibling_cancel_exactly_once and .cleanup' "$output.multiplex" >/dev/null
grep -q "C1 independent H3 cancel request admitted" "$tmpdir/peer.log"
echo "C1 outbound H3: HEAD root and sibling metadata with independent GET siblings"
if ! php -d display_errors=stderr -d log_errors=1 tests/fixtures/http3_outbound_multiplex.php "$port" "$tmpdir/cert.pem" head >"$output.head" 2>"$tmpdir/head.err"; then
    cat "$tmpdir/head.err" "$tmpdir/peer.log"
    exit 1
fi
jq -e '.verified and .single_quic_session and .concurrent_requests == 4
    and .responses_completed == 4 and .exact_responses
    and .native_sibling_cancel_exactly_once and .head_root_and_sibling_verified
    and .cleanup' "$output.head" >/dev/null
if [ "$#" -ge 2 ]; then
    profile_output="$2"
    echo "C1: measuring native H3 transport"
    php benchmarks/u05_native_h3_transport_profile.php "$port" "$tmpdir/cert.pem" 16 >"$profile_output"
    cat "$profile_output"
    jq -e '.schema == 1 and .real_network_io == true and .verified_tls_alpn == true
        and .correctness_passed == true and .concurrency == 1
        and .requests_total == 16 and .successful_requests == 16 and .errors_total == 0
        and .successful_rps > 0 and .p99_ms > 0 and .peak_memory_bytes > 0
        and .parent_cpu_ms > 0 and .full_end_to_end_certification == false' "$profile_output" >/dev/null
fi

if [ "$#" -ge 3 ]; then
    tcp_port="$(php -r '
        $socket = stream_socket_server("tcp://127.0.0.1:0", $code, $reason);
        if (!is_resource($socket)) { throw new RuntimeException($reason); }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if (!is_string($name)) { throw new RuntimeException("Missing ephemeral TCP port."); }
        echo substr(strrchr($name, ":"), 1);
    ')"
    "$python" -u tests/interop/independent_outbound_h12_server.py "$tcp_port" \
        "$tmpdir/cert.pem" "$tmpdir/key.pem" "$tmpdir/h12-ready" >"$tmpdir/h12.log" 2>&1 &
    h12_pid=$!
    for attempt in $(seq 1 100); do
        if [ -s "$tmpdir/h12-ready" ]; then break; fi
        if ! kill -0 "$h12_pid" 2>/dev/null; then cat "$tmpdir/h12.log"; exit 1; fi
        sleep 0.03
    done
    test -s "$tmpdir/h12-ready" || { cat "$tmpdir/h12.log"; exit 1; }
    for protocol in h1 h2 h3; do
        echo "C1 matched profile: $protocol"
        if ! php -d display_errors=stderr -d log_errors=1 benchmarks/u07_outbound_protocol_profile.php "$protocol" "$tcp_port" "$port" \
            "$tmpdir/cert.pem" 24 >"$tmpdir/$protocol.json" 2>"$tmpdir/$protocol.err"; then
            echo "C1 matched $protocol profile failed: stderr and stdout follow"
            cat "$tmpdir/$protocol.err" "$tmpdir/$protocol.json" "$tmpdir/h12.log" "$tmpdir/peer.log"
            exit 1
        fi
        cat "$tmpdir/$protocol.json"
    done
    jq -s '{schema:1,matched_payload:true,workloads:.,release_saturation_certified:false}' \
        "$tmpdir/h1.json" "$tmpdir/h2.json" "$tmpdir/h3.json" >"$3"
    jq -e '.schema == 1 and .matched_payload and (.workloads | length) == 3
        and ([.workloads[] | select(.successful_requests == 24 and .errors == 0
            and .successful_rps > 0 and .p99_ms > 0
            and .parent_cpu_ms > 0 and .parent_peak_rss_bytes > 0)] | length) == 3' "$3" >/dev/null
fi
