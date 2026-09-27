#!/usr/bin/env bash
set -euo pipefail

phase_seconds="${1:-3}"
output_dir="${2:-benchmark-results/adaptive-http3}"
python_bin="${RUNWIRE_AIOQUIC_PYTHON:-/tmp/runwire-aioquic/bin/python}"
mkdir -p "$output_dir"

if [ ! -x "$python_bin" ]; then
  echo "aioquic benchmark python is unavailable: $python_bin" >&2
  exit 1
fi

tmpdir="$(mktemp -d)"
server_pid=""

cleanup_server() {
  if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
    kill -TERM "$server_pid" 2>/dev/null || true
    for _ in $(seq 1 150); do
      if ! kill -0 "$server_pid" 2>/dev/null; then
        break
      fi
      sleep 0.02
    done
    if kill -0 "$server_pid" 2>/dev/null; then
      kill -KILL "$server_pid" 2>/dev/null || true
    fi
    wait "$server_pid" 2>/dev/null || true
  fi
  server_pid=""
}

cleanup() {
  cleanup_server
  rm -rf "$tmpdir"
}
trap cleanup EXIT

certificate="$tmpdir/cert.pem"
private_key="$tmpdir/key.pem"
openssl req -x509 -newkey rsa:2048 -nodes -days 1   -subj '/CN=localhost'   -keyout "$private_key"   -out "$certificate"   >/dev/null 2>&1

pick_udp_port() {
  php -r '
  $server = stream_socket_server("udp://127.0.0.1:0", $errno, $error, STREAM_SERVER_BIND);
  if (!is_resource($server)) {
      throw new RuntimeException($error);
  }
  $name = stream_socket_get_name($server, false);
  fclose($server);
  if (!is_string($name) || !str_contains($name, ":")) {
      throw new RuntimeException("Unable to resolve ephemeral UDP port.");
  }
  echo substr(strrchr($name, ":"), 1);
  '
}

run_trial() {
  local mode="$1"
  local trial="$2"
  local port ready payload result

  port="$(pick_udp_port)"
  ready="$tmpdir/ready-${mode}-${trial}"
  payload=16384
  php -d opcache.enable_cli=1 benchmarks/adaptive_http3_server.php     "$port" "$certificate" "$private_key" "$ready" "$mode" "$payload"     >"$tmpdir/server.log" 2>&1 &
  server_pid=$!

  for _ in $(seq 1 300); do
    if [ -f "$ready" ]; then
      break
    fi
    if ! kill -0 "$server_pid" 2>/dev/null; then
      cat "$tmpdir/server.log" >&2
      return 1
    fi
    sleep 0.02
  done
  test -f "$ready"

  result="$output_dir/http3-${mode}-trial-${trial}.json"
  "$python_bin" benchmarks/http3_adaptive_matrix.py     "$port" "$server_pid" "$mode" "$phase_seconds" "$payload"     > "$result"
  php -r '
  $data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  if (($data["correctness_passed"] ?? false) !== true) {
      throw new RuntimeException("Adaptive protocol trial failed correctness.");
  }
  ' "$result"
  cleanup_server
}

auto_files=()
fixed_files=()
for trial in 1 2 3 4 5; do
  if [ $((trial % 2)) -eq 1 ]; then
    order=(auto fixed)
  else
    order=(fixed auto)
  fi

  for mode in "${order[@]}"; do
    run_trial "$mode" "$trial"
    if [ "$mode" = "auto" ]; then
      auto_files+=("$output_dir/http3-auto-trial-${trial}.json")
    else
      fixed_files+=("$output_dir/http3-fixed-trial-${trial}.json")
    fi
  done
done

php benchmarks/adaptive_matrix_summary.php "${auto_files[@]}"   > "$output_dir/http3-auto-summary.json"
php benchmarks/adaptive_matrix_summary.php "${fixed_files[@]}"   > "$output_dir/http3-fixed-summary.json"
php benchmarks/adaptive_matrix_compare.php   "$output_dir/http3-auto-summary.json"   "$output_dir/http3-fixed-summary.json"   > "$output_dir/http3-comparison.json"

cat "$output_dir/http3-auto-summary.json"
cat "$output_dir/http3-fixed-summary.json"
cat "$output_dir/http3-comparison.json"
