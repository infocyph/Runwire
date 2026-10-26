#!/usr/bin/env bash
# Source-scoped, opt-in network socket syscall sampling (not a latency profile).
set -euo pipefail
output="${1:?Expected output directory}"
root="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$output"
output="$(cd "$output" && pwd)"
work="$(mktemp -d)"
tracer=""
cleanup() {
  if [ -n "$tracer" ]; then
    kill -TERM -- "-$tracer" 2>/dev/null || true
    wait "$tracer" 2>/dev/null || true
  fi
  rm -rf "$work"
}
trap cleanup EXIT
command -v strace >/dev/null || { echo "strace is required for this opt-in proof" >&2; exit 2; }
openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 1 -subj '/CN=localhost' \
  -addext 'subjectAltName=DNS:localhost' -keyout "$work/key.pem" \
  -out "$work/ca.pem" >/dev/null 2>&1

for mode in plain tls; do
  port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m);if(!$s)exit(1);$a=stream_socket_get_name($s,false);echo substr(strrchr($a,":"),1);fclose($s);')"
  if [ "$mode" = plain ]; then
    server=(php -d opcache.enable_cli=1 "$root/benchmarks/http1_server.php" "$port")
  else
    server=(php -d opcache.enable_cli=1 "$root/benchmarks/http1_tls_server.php" "$root" "$port" "$work/ca.pem" "$work/key.pem")
  fi
  # Isolate a process group so the PHP supervisor, worker and tracer all retire.
  setsid strace -ff -qq -yy -s 0 -e trace=network,read,write,readv,writev \
    -o "$output/$mode.trace" "${server[@]}" >"$work/$mode-server.log" 2>&1 &
  tracer=$!
  ready=0
  for attempt in $(seq 1 100); do
    if ! kill -0 "$tracer" 2>/dev/null; then
      cat "$work/$mode-server.log" >&2
      exit 1
    fi
    if php -r '
      $mode=$argv[1];$port=(int)$argv[2];$cert=$argv[3];
      $context=stream_context_create(["ssl"=>[
        "verify_peer"=>true,"verify_peer_name"=>true,"peer_name"=>"localhost",
        "cafile"=>$cert,"allow_self_signed"=>false
      ]]);
      $client=@stream_socket_client(($mode==="tls"?"tls":"tcp")."://127.0.0.1:".$port,
        $errno,$error,0.2,STREAM_CLIENT_CONNECT,$context);
      if(!is_resource($client))exit(1);
      fclose($client);
    ' "$mode" "$port" "$work/ca.pem" >/dev/null 2>&1; then
      ready=1
      break
    fi
    sleep 0.05
  done
  if [ "$ready" != 1 ]; then
    cat "$work/$mode-server.log" >&2
    echo "Traced server readiness failed" >&2
    exit 1
  fi
  # Exact request generator and keep-alive policy used by the 5×180s
  # untraced measurement. Tracing is intentionally short and intrusive.
  if [ "$mode" = plain ]; then
    RUNWIRE_RUNTIME_BUILD="${GITHUB_SHA:-local}" php \
      "$root/benchmarks/http1_sustained_bench.php" "$port" 2 0.1 1.2 "$tracer" \
      >"$output/$mode-clients.json"
  else
    RUNWIRE_RUNTIME_BUILD="${GITHUB_SHA:-local}" RUNWIRE_BENCH_TLS=1 \
      RUNWIRE_BENCH_TLS_CA="$work/ca.pem" php \
      "$root/benchmarks/http1_sustained_bench.php" "$port" 2 0.1 1.2 "$tracer" \
      >"$output/$mode-clients.json"
  fi
  jq -e '.correctness_passed == true
    and .errors_total == 0 and .timeouts_total == 0
    and .successful_requests == .requests_total and .successful_requests > 0
    and .connection_reuse == "keep-alive"
    and .socket_io.write_calls > 0 and .socket_io.read_calls > 0
    and .latency_ms.p99 > 0' "$output/$mode-clients.json" >/dev/null
  kill -TERM -- "-$tracer" 2>/dev/null || true
  wait "$tracer" 2>/dev/null || true
  tracer=""
  python3 "$root/benchmarks/u08_socket_syscall_parse.py" \
    "$output/$mode.trace" "$output/$mode-syscalls.json"
  jq -e '.socket_fd_read_family_successful_syscalls > 0 and
    .socket_fd_write_family_successful_syscalls > 0 and
    .trace_performance_overhead == true and .untraced_benchmark_equivalence_claimed == false' \
    "$output/$mode-syscalls.json" >/dev/null
done
