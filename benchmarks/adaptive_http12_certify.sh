#!/usr/bin/env bash
set -euo pipefail

phase_seconds="${1:-3}"
output_dir="${2:-benchmark-results/adaptive-http12}"
mkdir -p "$output_dir"

tmpdir="$(mktemp -d)"
server_pid=""

cleanup_server() {
  if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
    kill -TERM "$server_pid" 2>/dev/null || true
    for _ in $(seq 1 100); do
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

pick_port() {
  php -r '
  $server = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
  if (!is_resource($server)) {
      throw new RuntimeException($error);
  }
  $name = stream_socket_get_name($server, false);
  fclose($server);
  if (!is_string($name) || !str_contains($name, ":")) {
      throw new RuntimeException("Unable to resolve ephemeral port.");
  }
  echo substr(strrchr($name, ":"), 1);
  '
}

wait_port() {
  local port="$1"
  for _ in $(seq 1 200); do
    if php -r '
      $socket = @stream_socket_client("tcp://127.0.0.1:" . $argv[1], $errno, $error, 0.05);
      if (is_resource($socket)) {
          fclose($socket);
          exit(0);
      }
      exit(1);
    ' "$port" >/dev/null 2>&1; then
      sleep 0.05
      return 0
    fi
    if ! kill -0 "$server_pid" 2>/dev/null; then
      cat "$tmpdir/server.log" >&2
      return 1
    fi
    sleep 0.02
  done
  cat "$tmpdir/server.log" >&2
  return 1
}

run_trial() {
  local protocol="$1"
  local mode="$2"
  local trial="$3"
  local port payload result

  port="$(pick_port)"
  if [ "$protocol" = "http1" ]; then
    payload=2
    php -d opcache.enable_cli=1 benchmarks/adaptive_http_server.php       http1 "$port" "$mode" "$payload"       >"$tmpdir/server.log" 2>&1 &
  else
    payload=768
    php -d opcache.enable_cli=1 benchmarks/adaptive_http_server.php       h2 "$port" "$mode" "$payload" "$certificate" "$private_key"       >"$tmpdir/server.log" 2>&1 &
  fi
  server_pid=$!
  wait_port "$port"

  result="$output_dir/${protocol}-${mode}-trial-${trial}.json"
  if [ "$protocol" = "http1" ]; then
    php benchmarks/adaptive_http1_matrix.php       "$port" "$server_pid" "$mode" "$phase_seconds"       > "$result"
  else
    /tmp/runwire-h2/bin/python benchmarks/h2_adaptive_matrix.py       "$port" "$server_pid" "$mode" "$phase_seconds" "$payload"       > "$result"
  fi

  php -r '
  $data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  if (($data["correctness_passed"] ?? false) !== true) {
      throw new RuntimeException("Adaptive protocol trial failed correctness.");
  }
  ' "$result"
  cleanup_server
}

comparison_failed=0
for protocol in http1 h2; do
  auto_files=()
  fixed_files=()

  for trial in 1 2 3 4 5; do
    if [ $((trial % 2)) -eq 1 ]; then
      order=(auto fixed)
    else
      order=(fixed auto)
    fi

    for mode in "${order[@]}"; do
      run_trial "$protocol" "$mode" "$trial"
      if [ "$mode" = "auto" ]; then
        auto_files+=("$output_dir/${protocol}-auto-trial-${trial}.json")
      else
        fixed_files+=("$output_dir/${protocol}-fixed-trial-${trial}.json")
      fi
    done
  done

  php benchmarks/adaptive_matrix_summary.php "${auto_files[@]}"     > "$output_dir/${protocol}-auto-summary.json"
  php benchmarks/adaptive_matrix_summary.php "${fixed_files[@]}"     > "$output_dir/${protocol}-fixed-summary.json"
  if ! php benchmarks/adaptive_matrix_compare.php     "$output_dir/${protocol}-auto-summary.json"     "$output_dir/${protocol}-fixed-summary.json"     > "$output_dir/${protocol}-comparison.json"; then
    if [ "$protocol" = "http1" ]; then
      echo "HTTP/1.1 AUTO is not release-promoted; FIXED/NODELAY-on remains the production default." >&2
    else
      comparison_failed=1
    fi
  fi

  cat "$output_dir/${protocol}-auto-summary.json"
  cat "$output_dir/${protocol}-fixed-summary.json"
  cat "$output_dir/${protocol}-comparison.json"
done

exit "$comparison_failed"
