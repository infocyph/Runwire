#!/usr/bin/env bash
# Strict published 2.1.2 vs 3.0 independent verified-TLS h2, 5 x 180s pairs.
set -euo pipefail
test "$#" -eq 3
baseline="$(cd "$1" && pwd)"
candidate="$(cd "$2" && pwd)"
mkdir -p "$3"
out="$(cd "$3" && pwd)"
sha="$(git -C "$candidate" rev-parse HEAD)"
test "$(git -C "$baseline" rev-parse HEAD)" = 7e9bf20ad0737a187dfed77b723791b141b36378
test -r "$candidate/vendor/autoload.php"
test -r "$baseline/vendor/autoload.php"
python_bin="${PYTHON_BIN:-python3}"
"$python_bin" "$candidate/benchmarks/release_cpu_affinity.py" > "$out/affinity.json"
jq -e '.client_separate_physical_core == true' "$out/affinity.json" >/dev/null
server_cpu="$(jq -er '.server_cpu' "$out/affinity.json")"
client_cpu="$(jq -er '.client_cpu' "$out/affinity.json")"
tmp="$(mktemp -d)"
pid=""
sampler=""
flag=""
cleanup() {
  if [ -n "$flag" ]; then rm -f "$flag"; fi
  if [ -n "$sampler" ]; then wait "$sampler" 2>/dev/null || true; fi
  if [ -n "$pid" ]; then
    kill -TERM "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
  fi
  rm -rf "$tmp"
}
trap cleanup EXIT
openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 1 \
  -subj /CN=localhost -addext subjectAltName=DNS:localhost \
  -keyout "$tmp/key.pem" -out "$tmp/cert.pem" >/dev/null 2>&1
for trial in 1 2 3 4 5; do
  for slot in 1 2; do
    group=baseline
    if [ "$((trial % 2))" -eq 1 ] && [ "$slot" -eq 2 ]; then group=candidate; fi
    if [ "$((trial % 2))" -eq 0 ] && [ "$slot" -eq 1 ]; then group=candidate; fi
    root="$baseline"
    if [ "$group" = candidate ]; then root="$candidate"; fi
    port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m);if(!is_resource($s))throw new RuntimeException($m);$n=stream_socket_get_name($s,false);fclose($s);echo substr(strrchr($n,":"),1);')"
    (
      cd "$root"
      exec taskset -c "$server_cpu" php -d opcache.enable_cli=1 \
        benchmarks/adaptive_http_server.php h2 "$port" fixed 768 \
        "$tmp/cert.pem" "$tmp/key.pem"
    ) > "$out/$group-$trial-server.log" 2>&1 &
    pid="$!"
    ready=0
    for attempt in $(seq 1 220); do
      if ! kill -0 "$pid" 2>/dev/null; then
        cat "$out/$group-$trial-server.log" >&2
        exit 1
      fi
      if php -r '$s=stream_socket_client("tcp://127.0.0.1:".$argv[1],$e,$m,0.05);if(!is_resource($s))exit(1);fclose($s);' "$port" >/dev/null 2>&1; then
        ready=1
        break
      fi
      sleep .04
    done
    test "$ready" -eq 1
    flag="$out/sampling-$group-$trial"
    touch "$flag"
    "$python_bin" "$candidate/benchmarks/c4_process_resource_sampler.py" \
      "$pid" "$flag" "$out/$group-$trial-resources.json" &
    sampler="$!"
    (
      cd "$candidate"
      taskset -c "$client_cpu" "$python_bin" benchmarks/c4_matched_h2_peer.py \
        "$port" "$pid" > "$out/$group-$trial.json"
    ) || { tail -90 "$out/$group-$trial-server.log" >&2; exit 1; }
    rm -f "$flag"
    flag=""
    wait "$sampler"
    sampler=""
    jq -e '.correctness_passed == true and .warmup_seconds == 30 and
      .phase_seconds == 180 and .errors == 0 and .requests > 0' \
      "$out/$group-$trial.json" >/dev/null
    kill -TERM "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
    pid=""
  done
done
"$python_bin" "$candidate/benchmarks/c4_matched_h2_compare.py" "$out" "$sha"
