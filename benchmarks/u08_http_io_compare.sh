#!/usr/bin/env bash
# Paired native H1 plaintext / verified TLS response-cost profile.
# Diagnostic acceptance only; short runs do not certify release saturation.
set -euo pipefail
output="${1:?Expected output evidence JSON}"
trials="${RUNWIRE_COMPARE_TRIALS:-3}"
concurrency="${RUNWIRE_COMPARE_CONCURRENCY:-4}"
warmup="${RUNWIRE_COMPARE_WARMUP_SECONDS:-0.1}"
duration="${RUNWIRE_COMPARE_DURATION_SECONDS:-1.2}"
for value in "$trials" "$concurrency"; do
  [[ "$value" =~ ^[1-9][0-9]*$ ]] || { echo 'Trial/concurrency must be positive integers.' >&2; exit 2; }
done
[[ "$warmup" =~ ^[0-9]+([.][0-9]+)?$ && "$duration" =~ ^[0-9]+([.][0-9]+)?$ ]] || {
  echo 'Invalid warmup or duration.' >&2; exit 2
}
if [ "$trials" -gt 5 ] || [ "$concurrency" -gt 1024 ] || [ "$duration" = 0 ]; then
  echo 'Benchmark inputs exceed supported limits.' >&2; exit 2
fi
server_affinity=()
client_affinity=()
if [ -n "${RUNWIRE_COMPARE_SERVER_CPU:-}" ]; then
  server_affinity=(taskset -c "$RUNWIRE_COMPARE_SERVER_CPU")
fi
if [ -n "${RUNWIRE_COMPARE_CLIENT_CPU:-}" ]; then
  client_affinity=(taskset -c "$RUNWIRE_COMPARE_CLIENT_CPU")
fi
root="$(cd "$(dirname "$0")/.." && pwd)"
work="$(mktemp -d)"
plain_pid=""
tls_pid=""
cleanup() {
  exit_status=$?
  for pid in "$plain_pid" "$tls_pid"; do
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
      kill -TERM "$pid" 2>/dev/null || true
      wait "$pid" 2>/dev/null || true
    fi
  done
  if [ "${exit_status:-0}" -ne 0 ]; then
    for mode in plain tls; do
      if [ -f "$work/$mode.log" ]; then
        printf '%s\n' "--- $mode benchmark server ---" >&2
        tail -n 40 "$work/$mode.log" >&2
      fi
    done
    ls -l "$work" >&2 || true
  fi
  rm -rf "$work"
}
trap cleanup EXIT
mkdir -p "$(dirname "$output")"
openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 1   -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost'   -keyout "$work/key.pem" -out "$work/ca.pem" >/dev/null 2>&1
# Keep both sockets bound until their distinct port numbers are assigned.
mapfile -t reserved_ports < <(php -r '
  $first = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
  $second = stream_socket_server("tcp://127.0.0.1:0", $errno2, $error2);
  if (!is_resource($first) || !is_resource($second)) {
      fwrite(STDERR, "Cannot reserve separate benchmark TCP ports.");
      exit(1);
  }
  foreach ([$first, $second] as $socket) {
      $address = stream_socket_get_name($socket, false);
      if (!is_string($address) || !str_contains($address, ":")) {
          fwrite(STDERR, "Ephemeral TCP port allocation failed.");
          exit(1);
      }
      fwrite(STDOUT, substr(strrchr($address, ":"), 1) . "\n");
  }
  fclose($first);
  fclose($second);
')
if [ "${#reserved_ports[@]}" -ne 2 ] || [ "${reserved_ports[0]}" = "${reserved_ports[1]}" ]; then
  printf '%s\n' 'Separate benchmark ports were not reserved.' >&2
  exit 1
fi
plain_port="${reserved_ports[0]}"
tls_port="${reserved_ports[1]}"
"${server_affinity[@]}" php -d opcache.enable_cli=1 "$root/benchmarks/http1_server.php" "$plain_port" >"$work/plain.log" 2>&1 &
plain_pid=$!
"${server_affinity[@]}" php -d opcache.enable_cli=1 "$root/benchmarks/http1_tls_server.php" "$root" "$tls_port" "$work/ca.pem" "$work/key.pem" >"$work/tls.log" 2>&1 &
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
for trial in $(seq 1 "$trials"); do
  for mode in plain tls; do
    if [ "$mode" = plain ]; then
      port="$plain_port"; pid="$plain_pid"
      unset RUNWIRE_BENCH_TLS RUNWIRE_BENCH_TLS_CA || true
    else
      port="$tls_port"; pid="$tls_pid"
      export RUNWIRE_BENCH_TLS=1 RUNWIRE_BENCH_TLS_CA="$work/ca.pem"
    fi
    RUNWIRE_RUNTIME_BUILD="${GITHUB_SHA:-local}" "${client_affinity[@]}" php "$root/benchmarks/http1_sustained_bench.php" "$port" "$concurrency" "$warmup" "$duration" "$pid" >"$work/$mode-$trial.json"
    jq -c '{tls,correction: .correctness_passed,successful_requests,errors_total,
      p99_ms: .latency_ms.p99,stream_io: .socket_io,
      kernel_process_io: .server_process_io}' "$work/$mode-$trial.json" >&2
    jq -e '.correctness_passed == true and .errors_total == 0
      and .successful_requests == .requests_total and .successful_requests > 0
      and .socket_io.write_calls > 0 and .socket_io.read_calls > 0
      and .socket_io.write_bytes > 0 and .socket_io.read_bytes > 0
      and .server_process_io.scope == "whole-server-process-tree-not-socket-only"
      and .server_process_io.linux_procfs == true
      and .server_process_io.read_syscalls_delta >= 0
      and .server_process_io.write_syscalls_delta >= 0
      and .latency_ms.p99 > 0 and .rss_peak_bytes > 0' "$work/$mode-$trial.json" >/dev/null
  done
done
jq -s '.' "$work"/plain-*.json > "$work/plain-evidence.json"
jq -s '.' "$work"/tls-*.json > "$work/tls-evidence.json"
jq -n \
  --argjson trials "$trials" \
  --argjson concurrency "$concurrency" \
  --argjson duration "$duration" \
  --argjson warmup "$warmup" \
  --slurpfile plain "$work/plain-evidence.json" \
  --slurpfile tls "$work/tls-evidence.json" \
  '{
    schema: 1,
    candidate_build: $plain[0][0].runtime_build,
    workload: "same-runner-H1-plaintext-versus-verified-TLS",
    trials: $trials,
    concurrency: $concurrency,
    trial_seconds: $duration,
    warmup_seconds_per_trial: $warmup,
    source_scope: "instrumented-transport-measurement-not-final-3.0-release-certification",
    socket_write_scope: "PHP-stream-fwrite-calls-not-kernel-syscall-count",
    server_syscall_scope: "all-server-process-reads-writes-not-socket-only",
    plaintext: $plain[0],
    tls: $tls[0]
  }' > "$output"
jq -e --argjson count "$trials" '
  .schema == 1 and .trials == $count
  and (.plaintext | length) == $count and (.tls | length) == $count
  and all(.plaintext[], .tls[];
    .correctness_passed == true and .errors_total == 0
    and .successful_requests == .requests_total and .successful_requests > 0
    and .socket_io.write_calls > 0 and .socket_io.read_calls > 0
    and .server_process_io.scope == "whole-server-process-tree-not-socket-only"
    and .server_process_io.linux_procfs == true
    and .server_process_io.read_syscalls_delta >= 0
    and .server_process_io.write_syscalls_delta >= 0
    and .latency_ms.p99 > 0 and .rss_peak_bytes > 0)' "$output" >/dev/null
