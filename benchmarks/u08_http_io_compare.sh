#!/usr/bin/env bash
# Paired native H1 plaintext / verified TLS response-cost profile.
# Diagnostic acceptance only; short runs do not certify release saturation.
set -euo pipefail
output="${1:?Expected output evidence JSON}"
root="$(cd "$(dirname "$0")/.." && pwd)"
work="$(mktemp -d)"
plain_pid=""
tls_pid=""
cleanup() {
  for pid in "$plain_pid" "$tls_pid"; do
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
      kill -TERM "$pid" 2>/dev/null || true
      wait "$pid" 2>/dev/null || true
    fi
  done
  rm -rf "$work"
}
trap cleanup EXIT
mkdir -p "$(dirname "$output")"
openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 1   -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost'   -keyout "$work/key.pem" -out "$work/ca.pem" >/dev/null 2>&1
reserve_port() {
  php -r '
    $sock = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
    if (!is_resource($sock)) { throw new RuntimeException($error); }
    $address = stream_socket_get_name($sock, false);
    fclose($sock);
    if (!is_string($address)) { throw new RuntimeException("Cannot reserve benchmark TCP port."); }
    fwrite(STDOUT, substr(strrchr($address, ":"), 1));
  '
}
plain_port="$(reserve_port)"
tls_port="$(reserve_port)"
test "$plain_port" != "$tls_port"
php -d opcache.enable_cli=1 "$root/benchmarks/http1_server.php" "$plain_port" >"$work/plain.log" 2>&1 &
plain_pid=$!
php -d opcache.enable_cli=1 "$root/benchmarks/http1_tls_server.php" "$root" "$tls_port" "$work/ca.pem" "$work/key.pem" >"$work/tls.log" 2>&1 &
tls_pid=$!
for mode in plain tls; do
  ready=0
  if [ "$mode" = plain ]; then port="$plain_port"; pid="$plain_pid"; else port="$tls_port"; pid="$tls_pid"; fi
  for attempt in $(seq 1 100); do
    if ! kill -0 "$pid" 2>/dev/null; then cat "$work/$mode.log" >&2; exit 1; fi
    if RUNWIRE_COMPARE_CA="$work/ca.pem" php -r '
      $port=(int)$argv[1]; $mode=$argv[2];
      $context=stream_context_create(["ssl"=>[
        "verify_peer"=>true, "verify_peer_name"=>true,
        "peer_name"=>"localhost", "cafile"=>getenv("RUNWIRE_COMPARE_CA"),
      ]]);
      $client=@stream_socket_client(($mode==="tls"?"tls":"tcp")."://127.0.0.1:".$port,
        $errno,$error,0.15,STREAM_CLIENT_CONNECT,$context);
      if (!is_resource($client)) { exit(1); }
      fclose($client);
    ' "$port" "$mode" >/dev/null 2>&1; then ready=1; break; fi
    sleep 0.03
  done
  if [ "$ready" -ne 1 ]; then cat "$work/$mode.log" >&2; exit 1; fi
done
for trial in 1 2 3; do
  for mode in plain tls; do
    if [ "$mode" = plain ]; then
      port="$plain_port"; pid="$plain_pid"
      unset RUNWIRE_BENCH_TLS RUNWIRE_BENCH_TLS_CA || true
    else
      port="$tls_port"; pid="$tls_pid"
      export RUNWIRE_BENCH_TLS=1 RUNWIRE_BENCH_TLS_CA="$work/ca.pem"
    fi
    RUNWIRE_RUNTIME_BUILD="${GITHUB_SHA:-local}"       php "$root/benchmarks/http1_sustained_bench.php" "$port" 4 0.1 1.2 "$pid" >"$work/$mode-$trial.json"
    jq -e '.correctness_passed == true and .errors_total == 0
      and .successful_requests == .requests_total and .successful_requests > 0
      and .socket_io.write_calls > 0 and .socket_io.read_calls > 0
      and .socket_io.write_bytes > 0 and .socket_io.read_bytes > 0
      and .server_process_io.scope == "whole-server-process-tree-not-socket-only"
      and .server_process_io.linux_procfs == true
      and .server_process_io.read_syscalls_delta > 0
      and .server_process_io.write_syscalls_delta > 0
      and .latency_ms.p99 > 0 and .rss_peak_bytes > 0' "$work/$mode-$trial.json" >/dev/null
  done
done
jq -n   --slurpfile p1 "$work/plain-1.json" --slurpfile p2 "$work/plain-2.json" --slurpfile p3 "$work/plain-3.json"   --slurpfile t1 "$work/tls-1.json" --slurpfile t2 "$work/tls-2.json" --slurpfile t3 "$work/tls-3.json"   '{schema:1, candidate_build:($p1[0].runtime_build),
    workload:"same-runner-H1-plaintext-versus-verified-TLS", trials:3,
    source_scope:"development-transport-comparison-not-final-release-certification",
    socket_write_scope:"PHP-stream-fwrite-calls-not-kernel-syscall-count",
    server_syscall_scope:"all-server-process-reads-writes-not-socket-only",
    plaintext:[$p1[0],$p2[0],$p3[0]], tls:[$t1[0],$t2[0],$t3[0]]}' >"$output"
jq -e '.schema == 1 and .trials == 3
  and ([.plaintext[],.tls[] | select(.correctness_passed != true)] | length) == 0
  and ([.plaintext[],.tls[] | select(.server_process_io.write_syscalls_delta < 1)] | length) == 0' "$output" >/dev/null
