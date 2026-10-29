#!/usr/bin/env bash
# Five genuinely paired 180s WebSocket RFC6455 real 2.1.2-vs-3.0 trials.
set -euo pipefail
if [ "$#" -ne 3 ]; then
  echo "Usage: $0 <published-2.1.2-checkout> <candidate-checkout> <artifact-dir>" >&2
  exit 2
fi
baseline="$(cd "$1" && pwd)"
candidate="$(cd "$2" && pwd)"
mkdir -p "$3"
out="$(cd "$3" && pwd)"
sha="$(git -C "$candidate" rev-parse HEAD)"
test "$(git -C "$baseline" rev-parse HEAD)" = "7e9bf20ad0737a187dfed77b723791b141b36378"
test "$sha" != "$(git -C "$baseline" rev-parse HEAD)"
test -r "$baseline/vendor/autoload.php"
test -r "$candidate/vendor/autoload.php"
python3 "$candidate/benchmarks/release_cpu_affinity.py" > "$out/affinity.json"
jq -e '.client_separate_physical_core == true' "$out/affinity.json" >/dev/null
server_cpu="$(jq -r '.server_cpu' "$out/affinity.json")"
client_cpu="$(jq -r '.client_cpu' "$out/affinity.json")"
baseline_pid=""
candidate_pid=""
cleanup() {
  for pid in "$baseline_pid" "$candidate_pid"; do
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
      kill -TERM -- "-$pid" 2>/dev/null || kill -TERM "$pid" 2>/dev/null || true
      wait "$pid" 2>/dev/null || true
    fi
  done
}
trap cleanup EXIT
for group in baseline candidate; do
  root="$baseline"
  if [ "$group" = candidate ]; then root="$candidate"; fi
  port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m);if(!is_resource($s))throw new RuntimeException($m);$n=stream_socket_get_name($s,false);fclose($s);echo substr(strrchr($n,":"),1);')"
  printf '%s\n' "$port" > "$out/$group-port.txt"
  (
    cd "$root"
    exec setsid taskset -c "$server_cpu" php -d opcache.enable_cli=1 benchmarks/websocket_server.php "$port"
  ) > "$out/$group-server.log" 2>&1 &
  pid=$!
  if [ "$group" = baseline ]; then baseline_pid="$pid"; else candidate_pid="$pid"; fi
  ready=0
  for attempt in $(seq 1 180); do
    if ! kill -0 "$pid" 2>/dev/null; then
      cat "$out/$group-server.log" >&2
      exit 1
    fi
    if php -r '$s=stream_socket_client("tcp://127.0.0.1:".$argv[1],$e,$m,0.05);if(!is_resource($s))exit(1);fclose($s);' "$port" 2>/dev/null; then
      ready=1
      break
    fi
    sleep 0.04
  done
  if [ "$ready" -ne 1 ]; then echo "Published/candidate WebSocket peer did not start: $group" >&2; exit 1; fi
  (
    cd "$root"
    taskset -c "$client_cpu" php -d opcache.enable_cli=1 benchmarks/websocket_evidence.php \
      --port="$port" --concurrency=8 --trials=1 --duration=5 --soak-seconds=0 \
      > "$out/$group-warmup.json"
  )
  jq -e '.correctness_passed == true and .successful_messages > 0' "$out/$group-warmup.json" >/dev/null
done
for pair in 1 2 3 4 5; do
  order=(baseline candidate)
  if [ "$((pair%2))" -eq 0 ]; then order=(candidate baseline); fi
  for group in "${order[@]}"; do
    root="$baseline"
    pid="$baseline_pid"
    if [ "$group" = candidate ]; then root="$candidate"; pid="$candidate_pid"; fi
    port="$(cat "$out/$group-port.txt")"
    flag="$out/sampling-$group-$pair"
    touch "$flag"
    python3 "$candidate/benchmarks/c4_process_resource_sampler.py" \
      "$pid" "$flag" "$out/$group-$pair-resources.json" &
    sampler="$!"
    (
      cd "$root"
      taskset -c "$client_cpu" php -d opcache.enable_cli=1 benchmarks/websocket_evidence.php \
        --port="$port" --concurrency=8 --trials=1 --duration=180 --soak-seconds=0 \
        > "$out/$group-$pair.json"
    ) || {
      rm -f "$flag"
      wait "$sampler" 2>/dev/null || true
      echo "Real WebSocket $group trial $pair failed" >&2
      exit 1
    }
    rm -f "$flag"
    wait "$sampler"
    jq -e '.correctness_passed == true and .trials == 1 and
      .duration_seconds_per_trial == 180 and .successful_messages == .messages_total
      and .errors_total == 0 and .timeouts_total == 0 and .validation_failures == 0' \
      "$out/$group-$pair.json" >/dev/null
  done
done
python3 "$candidate/benchmarks/c4_matched_ws_compare.py" "$out" "$sha"
