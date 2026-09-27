#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 3 ]; then
  echo "Usage: $0 <control-root> <output-dir> <duration>" >&2
  exit 2
fi

control_root="$(cd "$1" && pwd)"
output_dir="$2"
duration="$3"
mkdir -p "$output_dir"

event_ini="$(php --ini | grep -oE '/[^, ]*event[^, ]*\.ini' | head -n 1 || true)"
if [ -z "$event_ini" ] || [ ! -f "$event_ini" ]; then
  echo "Unable to locate ext-event ini file." >&2
  php --ini >&2
  exit 1
fi

event_ini_disabled="$event_ini.runwire-disabled"
server_pid=""
event_enabled=1

cleanup_server() {
  if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
    kill -TERM "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
  server_pid=""
}

enable_event() {
  if [ -f "$event_ini_disabled" ]; then
    sudo mv "$event_ini_disabled" "$event_ini"
  fi
  event_enabled=1
}

disable_event() {
  if [ -f "$event_ini" ]; then
    sudo mv "$event_ini" "$event_ini_disabled"
  fi
  event_enabled=0
}

cleanup() {
  cleanup_server
  enable_event
}
trap cleanup EXIT

find_port() {
  php -r '
  $server = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
  if (!is_resource($server)) {
      throw new RuntimeException($error !== "" ? $error : "Unable to allocate port.");
  }
  $name = stream_socket_get_name($server, false);
  fclose($server);
  echo substr(strrchr((string) $name, ":"), 1);
  '
}

wait_ready() {
  local port="$1"
  local log="$2"

  for _ in $(seq 1 250); do
    if php -r '
      set_error_handler(static fn(): bool => true);
      $socket = stream_socket_client("tcp://127.0.0.1:" . $argv[1], $errno, $error, 0.05);
      restore_error_handler();
      if (!is_resource($socket)) {
          exit(1);
      }
      fclose($socket);
    ' "$port" >/dev/null 2>&1; then
      return 0
    fi

    if ! kill -0 "$server_pid" 2>/dev/null; then
      cat "$log" >&2
      return 1
    fi
    sleep 0.02
  done

  cat "$log" >&2
  return 1
}

select_backend() {
  local backend="$1"

  if [ "$backend" = "event" ]; then
    enable_event
    php -r '
    require $argv[1] . "/vendor/autoload.php";
    if (!\Infocyph\Runwire\Loop\EventLoop::supported()) {
        throw new RuntimeException("EventLoop backend is not available.");
    }
    ' "$control_root"
  else
    disable_event
    php -r '
    require $argv[1] . "/vendor/autoload.php";
    if (\Infocyph\Runwire\Loop\EventLoop::supported()) {
        throw new RuntimeException("SelectLoop validation still sees ext-event.");
    }
    ' "$control_root"
  fi
}

run_one() {
  local backend="$1"
  local payload="$2"
  local concurrency="$3"
  local trial="$4"
  local port
  local expected_body
  local label
  local log

  select_backend "$backend"
  port="$(find_port)"
  label="$backend-payload$payload-c$concurrency-trial$trial"
  log="$output_dir/$label-server.log"

  RUNWIRE_BENCH_RUNTIME_ROOT="$control_root"     php -d opcache.enable_cli=1       "$control_root/benchmarks/http12_lab_server.php"       "$port" "$payload" 1 1 - - >"$log" 2>&1 &
  server_pid=$!
  wait_ready "$port" "$log"

  expected_body="$(php -r 'echo str_repeat("x", (int) $argv[1]);' "$payload")"

  RUNWIRE_RUNTIME_VERSION="2.0-$backend"   RUNWIRE_RUNTIME_BUILD="$(git -C "$control_root" rev-parse HEAD)"   RUNWIRE_INSTRUMENTATION="same-runner-backend-payload$payload-c$concurrency"   RUNWIRE_BENCH_TCP_NODELAY=0   RUNWIRE_BENCH_TLS=0   RUNWIRE_BENCH_EXPECTED_BODY="$expected_body"     php -d opcache.enable_cli=1       "$control_root/benchmarks/http1_sustained_bench.php"       "$port" "$concurrency" 1 "$duration" "$server_pid"       >"$output_dir/$label.json"

  jq -e '
    .correctness_passed == true
    and .successful_requests == .requests_total
    and .errors_total == 0
    and .timeouts_total == 0
    and .validation_failures == 0
  ' "$output_dir/$label.json" >/dev/null

  cleanup_server
}

for payload in 2 16384; do
  for concurrency in 16 64; do
    event_files=()
    select_files=()

    for trial in 1 2 3 4 5; do
      if [ $((trial % 2)) -eq 1 ]; then
        run_one select "$payload" "$concurrency" "$trial"
        run_one event "$payload" "$concurrency" "$trial"
      else
        run_one event "$payload" "$concurrency" "$trial"
        run_one select "$payload" "$concurrency" "$trial"
      fi

      select_files+=("$output_dir/select-payload$payload-c$concurrency-trial$trial.json")
      event_files+=("$output_dir/event-payload$payload-c$concurrency-trial$trial.json")
    done

    php "$control_root/benchmarks/sustained_summary.php" "${select_files[@]}"       >"$output_dir/select-payload$payload-c$concurrency-summary.json"
    php "$control_root/benchmarks/sustained_summary.php" "${event_files[@]}"       >"$output_dir/event-payload$payload-c$concurrency-summary.json"
  done
done

enable_event
