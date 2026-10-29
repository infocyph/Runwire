#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 1 ]; then
  printf '%s\n' 'Usage: u04_tls_smoke.sh <output.json>' >&2
  exit 2
fi

out="$1"
root="$(cd "$(dirname "$0")/.." && pwd)"
workdir="$(mktemp -d)"
pid=""

cleanup() {
  status=$?
  if [ "$status" -ne 0 ]; then
    printf '%s\n' "Verified TLS smoke failed (exit $status)." >&2
    if [ -f "$out" ]; then
      jq -c '{correctness_passed, tls, successful_requests, requests_total, errors_total, timeouts_total, validation_failures, latency_ms, socket_io}' "$out" >&2 || true
    fi
    if [ -f "$workdir/server.log" ]; then
      printf '%s\n' '--- verified TLS benchmark server diagnostics ---' >&2
      tail -n 50 "$workdir/server.log" >&2
    fi
  fi
  if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
    kill -TERM "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
  fi
  rm -rf "$workdir"
}
trap cleanup EXIT

openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 1 \
  -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost' \
  -keyout "$workdir/server.key" -out "$workdir/ca.pem" >/dev/null 2>&1

port="$(php -r '
$listener = stream_socket_server("tcp://127.0.0.1:0", $errno, $message);
if (!is_resource($listener)) {
    throw new RuntimeException($message);
}
$addr = stream_socket_get_name($listener, false);
fclose($listener);
if (!is_string($addr)) {
    throw new RuntimeException("No allocated local address.");
}
fwrite(STDOUT, substr(strrchr($addr, ":"), 1));
')"

php -d opcache.enable_cli=1 "$root/benchmarks/http1_tls_server.php" \
  "$root" "$port" "$workdir/ca.pem" "$workdir/server.key" \
  > "$workdir/server.log" 2>&1 &
pid=$!

ready=0
for _ in $(seq 1 100); do
  if RUNWIRE_BENCH_TLS_CA="$workdir/ca.pem" php -r '
  $ctx = stream_context_create(["ssl" => [
      "cafile" => getenv("RUNWIRE_BENCH_TLS_CA"),
      "verify_peer" => true,
      "verify_peer_name" => true,
      "peer_name" => "localhost",
  ]]);
  $stream = @stream_socket_client(
      "tls://127.0.0.1:" . $argv[1], $errno, $message, 0.15,
      STREAM_CLIENT_CONNECT, $ctx,
  );
  if (!is_resource($stream)) {
      exit(1);
  }
  fclose($stream);
  ' "$port" >/dev/null 2>&1; then
    ready=1
    break
  fi
  if ! kill -0 "$pid" 2>/dev/null; then
    cat "$workdir/server.log" >&2
    exit 1
  fi
  sleep 0.02
done
if [ "$ready" -ne 1 ]; then
  cat "$workdir/server.log" >&2
  exit 1
fi

RUNWIRE_BENCH_TLS=1 RUNWIRE_BENCH_TLS_CA="$workdir/ca.pem" \
  RUNWIRE_RUNTIME_VERSION=3.0-smoke \
  php "$root/benchmarks/http1_sustained_bench.php" "$port" 2 0.1 1.0 "$pid" > "$out"

jq -e '.tls == "on-verified" and .workload == "verified-tls-H1"
  and .connection_reuse == "keep-alive" and .response_body_bytes == 2
  and .correctness_passed == true and .requests_total > 0
  and .successful_requests == .requests_total
  and .errors_total == 0 and .timeouts_total == 0
  and .validation_failures == 0 and .latency_ms.p99 > 0
  and .rss_peak_bytes > 0 and .socket_io.write_calls > 0
  and .socket_io.write_bytes > 0 and .socket_io.read_calls > 0
  and .socket_io.read_bytes > 0' "$out" >/dev/null
