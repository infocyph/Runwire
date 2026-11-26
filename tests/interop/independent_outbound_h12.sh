#!/usr/bin/env bash
set -euo pipefail
output="${1:?Expected matched outbound H1/H2 evidence JSON file}"
python="${RUNWIRE_H2_PYTHON:-python3}"
tmpdir="$(mktemp -d)"
peer_pid=""
cleanup() {
    if [ -n "$peer_pid" ] && kill -0 "$peer_pid" 2>/dev/null; then
        kill -TERM "$peer_pid" 2>/dev/null || true
        wait "$peer_pid" 2>/dev/null || true
    fi
    rm -rf "$tmpdir"
}
trap cleanup EXIT
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
    -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' \
    -keyout "$tmpdir/key.pem" -out "$tmpdir/cert.pem" >/dev/null 2>&1
port="$(php -r '
$socket = stream_socket_server("tcp://127.0.0.1:0", $code, $reason);
if (!is_resource($socket)) { throw new RuntimeException($reason); }
$name = stream_socket_get_name($socket, false);
fclose($socket);
if (!is_string($name)) { throw new RuntimeException("Missing ephemeral TCP port."); }
echo substr(strrchr($name, ":"), 1);
')"
"$python" -u tests/interop/independent_outbound_h12_server.py "$port" \
    "$tmpdir/cert.pem" "$tmpdir/key.pem" "$tmpdir/ready" >"$tmpdir/peer.log" 2>&1 &
peer_pid=$!
for attempt in $(seq 1 100); do
    if [ -s "$tmpdir/ready" ]; then break; fi
    if ! kill -0 "$peer_pid" 2>/dev/null; then cat "$tmpdir/peer.log"; exit 1; fi
    sleep 0.03
done
test -s "$tmpdir/ready" || { cat "$tmpdir/peer.log"; exit 1; }
mkdir -p "$(dirname "$output")"
for protocol in h1 h2; do
    if ! php -d display_errors=stderr -d log_errors=1 benchmarks/u07_outbound_protocol_profile.php "$protocol" "$port" \
        "$tmpdir/cert.pem" 24 >"$tmpdir/$protocol.json" 2>"$tmpdir/$protocol.err"; then
        cat "$tmpdir/$protocol.err" "$tmpdir/$protocol.json" "$tmpdir/peer.log"
        exit 1
    fi
    cat "$tmpdir/$protocol.json"
done
jq -s '{schema:1,matched_payload:true,workloads:.,release_saturation_certified:false}' \
    "$tmpdir/h1.json" "$tmpdir/h2.json" >"$output"
jq -e '.schema == 1 and .matched_payload and (.workloads | length) == 2
    and ([.workloads[].protocol] | sort) == ["h1","h2"]
    and ([.workloads[] | select(.successful_requests == 24 and .errors == 0
        and .verified_tls_alpn and .matching_body
        and .successful_rps > 0 and .p99_ms > 0
        and .parent_cpu_ms > 0 and .parent_peak_rss_bytes > 0)] | length) == 2' "$output" >/dev/null
