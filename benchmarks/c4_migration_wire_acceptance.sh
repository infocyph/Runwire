#!/usr/bin/env bash
# Compare real published 2.1.2 and unreleased 3.0 HTTP behavior on GitHub.
# This is a targeted behavioral migration regression, not complete U16.12.
set -euo pipefail

if [ "$#" -ne 3 ]; then
  echo "Usage: $0 <2.1.2-checkout> <3.0-checkout> <output-directory>" >&2
  exit 2
fi

baseline="$(cd "$1" && pwd)"
candidate="$(cd "$2" && pwd)"
mkdir -p "$3"
out="$(cd "$3" && pwd)"
expected_baseline="7e9bf20ad0737a187dfed77b723791b141b36378"
actual_baseline="$(git -C "$baseline" rev-parse HEAD)"
actual_candidate="$(git -C "$candidate" rev-parse HEAD)"
if [ "$actual_baseline" != "$expected_baseline" ] ||
   ! [[ "$actual_candidate" =~ ^[0-9a-f]{40}$ ]] ||
   [ "$actual_candidate" = "$actual_baseline" ]; then
  echo "C4 migration behavioral probe requires independent immutable 2.1.2/3.0 source." >&2
  exit 1
fi
for root in "$baseline" "$candidate"; do
  test -r "$root/vendor/autoload.php"
  test -r "$root/benchmarks/http1_server.php"
done

pid=""
cleanup() {
  if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
    kill -TERM "$pid" 2>/dev/null || true
    for _ in $(seq 1 150); do
      if ! kill -0 "$pid" 2>/dev/null; then break; fi
      sleep 0.02
    done
    if kill -0 "$pid" 2>/dev/null; then kill -KILL "$pid" 2>/dev/null || true; fi
    wait "$pid" 2>/dev/null || true
  fi
  pid=""
}
trap cleanup EXIT

declare -a cases=(
  'GET /benchmark 200 ok'
  'GET /benchmark/subpath 200 ok'
  'GET /invalid 400 bad'
  'POST /benchmark 400 bad'
)
for entry in "2.1.2:$baseline" "3.0:$candidate"; do
  label="${entry%%:*}"
  root="${entry#*:}"
  port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m);if(!is_resource($s))throw new RuntimeException($m);$n=stream_socket_get_name($s,false);fclose($s);echo substr(strrchr((string)$n,":"),1);')"
  (
    cd "$root"
    exec php -d opcache.enable_cli=1 benchmarks/http1_server.php "$port"
  ) > "$out/$label-server.log" 2>&1 &
  pid=$!
  ready=0
  for _ in $(seq 1 200); do
    if ! kill -0 "$pid" 2>/dev/null; then
      cat "$out/$label-server.log" >&2
      exit 1
    fi
    if curl --fail --silent --show-error --max-time 2 \
      -H 'Connection: close' "http://127.0.0.1:$port/benchmark" -o /dev/null 2>/dev/null; then
      ready=1
      break
    fi
    sleep 0.025
  done
  if [ "$ready" -ne 1 ]; then
    cat "$out/$label-server.log" >&2
    exit 1
  fi

  case_no=0
  for spec in "${cases[@]}"; do
    read -r method path status body <<< "$spec"
    case_no=$((case_no + 1))
    headers="$out/$label-$case_no.headers"
    reply="$out/$label-$case_no.body"
    observed="$(curl --silent --show-error --http1.1 --max-time 5 \
      -H 'Connection: close' -X "$method" -D "$headers" -o "$reply" \
      -w '%{http_code}' "http://127.0.0.1:$port$path")"
    if [ "$observed" != "$status" ] || [ "$(cat "$reply")" != "$body" ]; then
      echo "C4 $label mismatch in $method $path: HTTP $observed." >&2
      exit 1
    fi
    if ! grep -Eiq '^content-type:[[:space:]]*text/plain' "$headers" ||
       ! grep -Eiq "^content-length:[[:space:]]*${#body}[[:space:]]*$" "$headers"; then
      echo "C4 $label response headers or body length mismatch: $method $path." >&2
      exit 1
    fi
    printf '%s\n' "$label $method $path $observed $body" >> "$out/wire-cases.txt"
  done
  cleanup
done

# The resulting proof is deliberately scoped to four wire-level behaviors.
jq -n --arg baseline "$actual_baseline" --arg candidate "$actual_candidate" \
  --arg version "$(php -r 'echo PHP_VERSION;')" \
  --rawfile observed "$out/wire-cases.txt" \
  '{schema:1,published_baseline_sha:$baseline,candidate_sha:$candidate,
    php_version:$version,method:"live independent native HTTP/1.1 server",
    tested_behavior:["GET valid","GET valid subpath","GET invalid","POST rejected"],
    cases_per_version:4,behavioral_cases_passed:8,
    identical_expected_wire_contract:true,complete_migration_audit:false,
    observations:$observed}' > "$out/migration-wire-evidence.json"
jq -e '.behavioral_cases_passed == 8 and .complete_migration_audit == false' \
  "$out/migration-wire-evidence.json" >/dev/null
cat "$out/migration-wire-evidence.json"
