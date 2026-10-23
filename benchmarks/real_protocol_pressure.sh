#!/usr/bin/env bash
# Real independent H2/H3 pressure profiles. Bounded correctness diagnostics,
# not 5x180s matched release certification.
set -euo pipefail

protocol="${1:?Usage: real_protocol_pressure.sh <h2|h3> [output-dir]}"
output_dir="${2:-benchmark-results/protocol-pressure}"
case "$protocol" in
  h2)
    python_bin="${RUNWIRE_H2_PYTHON:-/tmp/runwire-h2/bin/python}"
    payload=16384
    cases=(
      '{"id":"h2-hpack-dynamic","scenario":"hpack","low":4,"medium":8,"high":16}'
      '{"id":"h2-flow-window","scenario":"flow","low":4,"medium":8,"high":16}'
      '{"id":"h2-slow-peer","scenario":"pressure","low":4,"medium":8,"high":16}'
    )
    ;;
  h3)
    python_bin="${RUNWIRE_AIOQUIC_PYTHON:-/tmp/runwire-aioquic/bin/python}"
    payload=16384
    cases=(
      '{"id":"h3-qpack-blocking","scenario":"qpack","low":2,"medium":4,"high":8}'
      '{"id":"h3-upload-pressure","scenario":"upload","upload_bytes":1024,"low":2,"medium":4,"high":8}'
      '{"id":"h3-connection-churn","scenario":"churn","low":2,"medium":3,"high":4}'
    )
    ;;
  *) echo 'Only h2 or h3 is supported.' >&2; exit 1 ;;
esac

test -x "$python_bin" || { echo "Independent client not available: $python_bin" >&2; exit 1; }
mkdir -p "$output_dir"
workdir="$(mktemp -d)"
server_pid=""
cleanup_server() {
  if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
    kill -TERM "$server_pid" || true
    for _ in $(seq 1 100); do
      if ! kill -0 "$server_pid" 2>/dev/null; then break; fi
      sleep 0.02
    done
    if kill -0 "$server_pid" 2>/dev/null; then kill -KILL "$server_pid" || true; fi
    wait "$server_pid" || true
  fi
  server_pid=""
}
cleanup() { cleanup_server; rm -rf "$workdir"; }
trap cleanup EXIT

openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=localhost' \
  -keyout "$workdir/key.pem" -out "$workdir/cert.pem" >/dev/null 2>&1

port_for() {
  php -r '
    $socket = stream_socket_server(($argv[1] === "h3" ? "udp" : "tcp")
        . "://127.0.0.1:0", $errno, $error, STREAM_SERVER_BIND);
    if (!is_resource($socket)) { throw new RuntimeException($error); }
    $addr = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($addr) || !str_contains($addr, ":")) {
        throw new RuntimeException("Could not determine benchmark port.");
    }
    echo substr(strrchr($addr, ":"), 1);
  ' "$protocol"
}

for case_json in "${cases[@]}"; do
  id="$(jq -nr --argjson spec "$case_json" '$spec.id')"
  port="$(port_for)"
  ready="$workdir/ready-$id"
  case "$protocol" in
    h2)
      php -d opcache.enable_cli=1 benchmarks/adaptive_http_server.php \
        h2 "$port" fixed "$payload" "$workdir/cert.pem" "$workdir/key.pem" \
        >"$workdir/$id.log" 2>&1 &
      ;;
    h3)
      php -d opcache.enable_cli=1 benchmarks/adaptive_http3_server.php \
        "$port" "$workdir/cert.pem" "$workdir/key.pem" "$ready" fixed "$payload" \
        >"$workdir/$id.log" 2>&1 &
      ;;
  esac
  server_pid=$!

  started=0
  for attempt in $(seq 1 150); do
    if ! kill -0 "$server_pid" 2>/dev/null; then
      cat "$workdir/$id.log" >&2
      exit 1
    fi
    if [ "$protocol" = h3 ]; then
      if [ -s "$ready" ]; then started=1; break; fi
    elif php -r '
      $s = @stream_socket_client("tcp://127.0.0.1:" . $argv[1], $errno, $error, 0.05);
      if (!is_resource($s)) { exit(1); }
      fclose($s);
    ' "$port" >/dev/null 2>&1; then
      started=1
      break
    fi
    sleep 0.04
  done
  if [ "$started" -ne 1 ]; then
    cat "$workdir/$id.log" >&2
    echo "Real protocol pressure fixture failed readiness: $id" >&2
    exit 1
  fi

  result="$output_dir/$id.json"
  export RUNWIRE_ADAPTIVE_CASE="$case_json"
  export RUNWIRE_ADAPTIVE_TRIAL=1
  export RUNWIRE_ADAPTIVE_BUILD="${RUNWIRE_RUNTIME_BUILD:-${GITHUB_SHA:-unrecorded}}"
  export RUNWIRE_ADAPTIVE_ENVIRONMENT="real-protocol-pressure:$(uname -sr):$(php -r 'echo PHP_VERSION;')"
  if [ "$protocol" = h2 ]; then
    "$python_bin" benchmarks/h2_adaptive_matrix.py "$port" "$server_pid" fixed 2 "$payload" >"$result"
  else
    "$python_bin" benchmarks/http3_adaptive_matrix.py "$port" "$server_pid" fixed 2 "$payload" >"$result"
  fi
  jq -e '
    .correctness_passed == true
    and .mode == "fixed"
    and ([.phases[] | select(.correctness_passed != true or .requests <= 0 or .errors != 0)] | length) == 0
    and ([.phases[] | select(.p99_ms < 0 or .throughput_rps <= 0 or .rss_peak_bytes <= 0)] | length) == 0
  ' "$result" >/dev/null

  if [ "$id" = h2-flow-window ]; then
    jq -e '[.phases[].observed.constrained_flow_bytes] | add > 0' "$result" >/dev/null
  elif [ "$id" = h2-slow-peer ]; then
    jq -e '[.phases[].observed.delayed_reads] | add > 0' "$result" >/dev/null
  elif [ "$id" = h2-hpack-dynamic ]; then
    jq -e '[.phases[].observed.hpack_dynamic_table_entries] | max > 0' "$result" >/dev/null
  elif [ "$id" = h3-qpack-blocking ]; then
    jq -e '[.phases[].observed.qpack_delayed_headers] | add > 0' "$result" >/dev/null
  elif [ "$id" = h3-upload-pressure ]; then
    jq -e '[.phases[].observed.upload_bytes] | add > 0' "$result" >/dev/null
  elif [ "$id" = h3-connection-churn ]; then
    jq -e '[.phases[].observed.connections] | add > 0' "$result" >/dev/null
  fi

  jq -c '{protocol, workload, correctness_passed,
          phases: [.phases | to_entries[] | {tier: .key, successful_rps: .value.throughput_rps,
            p99_ms: .value.p99_ms, cpu_percent: .value.cpu_percent,
            client_cpu_percent: .value.client_cpu_percent, rss_peak_bytes: .value.rss_peak_bytes,
            observed: .value.observed}]}' "$result"
  cleanup_server
done
unset RUNWIRE_ADAPTIVE_CASE
