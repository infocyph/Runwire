#!/usr/bin/env bash
# Source-pinned C4 native H1 capacity evidence. Diagnostic only, not U15.09.
set -euo pipefail

output_dir="${1:?Usage: c4_native_concurrency_sweep.sh <output-directory>}"
mkdir -p "$output_dir"
output_dir="$(cd "$output_dir" && pwd)"

command -v jq >/dev/null
command -v taskset >/dev/null
root="$(cd "$(dirname "$0")/.." && pwd)"
sha="$(git -C "$root" rev-parse HEAD)"
cpu_json="$(python3 "$root/benchmarks/release_cpu_affinity.py")"
printf '%s\n' "$cpu_json" | jq -e '.client_separate_physical_core == true and .server_cpu != .client_cpu' >/dev/null
printf '%s\n' "$cpu_json" > "$output_dir/affinity.json"
server_cpu="$(jq -r '.server_cpu' "$output_dir/affinity.json")"
client_cpu="$(jq -r '.client_cpu' "$output_dir/affinity.json")"

port="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0", $e, $m); if (!is_resource($s)) { throw new RuntimeException($m); } $n = stream_socket_get_name($s, false); fclose($s); if (!is_string($n)) { throw new RuntimeException("No socket name"); } echo substr(strrchr($n, ":"), 1);')"
server_pid=""
cleanup() {
  if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
    kill -TERM "$server_pid" 2>/dev/null || true
    for _ in $(seq 1 100); do
      if ! kill -0 "$server_pid" 2>/dev/null; then break; fi
      sleep 0.02
    done
    if kill -0 "$server_pid" 2>/dev/null; then
      kill -KILL "$server_pid" 2>/dev/null || true
    fi
    wait "$server_pid" 2>/dev/null || true
  fi
}
trap cleanup EXIT

(
  cd "$root"
  exec taskset -c "$server_cpu" php -d opcache.enable_cli=1 benchmarks/http1_server.php "$port"
) > "$output_dir/server.log" 2>&1 &
server_pid=$!
ready=0
for _ in $(seq 1 200); do
  if ! kill -0 "$server_pid" 2>/dev/null; then
    cat "$output_dir/server.log" >&2
    exit 1
  fi
  if php -r '$s = @stream_socket_client("tcp://127.0.0.1:" . $argv[1], $e, $m, 0.05); if (!is_resource($s)) { exit(1); } fclose($s);' "$port" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 0.025
done
if [ "$ready" -ne 1 ]; then
  cat "$output_dir/server.log" >&2
  exit 1
fi

export RUNWIRE_RUNTIME_BUILD="$sha"
export RUNWIRE_RUNTIME_VERSION="3.0-development"
export RUNWIRE_INSTRUMENTATION="c4-native-h1-isolated-core-concurrency-diagnostic"
export RUNWIRE_PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
export RUNWIRE_OPCACHE="enabled-cli"
export RUNWIRE_EXTENSION_VERSIONS="$(php -r '$a=[]; foreach(["pcntl", "posix", "event", "Zend OPcache"] as $e) { if(extension_loaded($e)) { $v=phpversion($e); $a[]=$e."=".(is_string($v)&&$v!==""?$v:"builtin"); } } echo implode(",", $a);')"

# A capacity curve is not a matched baseline regression or proof of generator headroom.
for concurrency in 1 4 16 32 64; do
  output="$output_dir/h1-concurrency-$concurrency.json"
  /usr/bin/time -f '%U %S %e' -o "$output_dir/generator-$concurrency.time" \
    taskset -c "$client_cpu" php -d opcache.enable_cli=1 "$root/benchmarks/http1_sustained_bench.php" \
    "$port" "$concurrency" 3 12 "$server_pid" > "$output"
  python3 - "$output_dir/generator-$concurrency.time" "$output_dir/generator-$concurrency.json" <<'PY'
import json
import pathlib
import sys
parts = pathlib.Path(sys.argv[1]).read_text().strip().split()
if len(parts) != 3:
    raise SystemExit("missing real client CPU/elapsed time from GNU time")
user, system, elapsed = [float(x) for x in parts]
if elapsed <= 0 or user < 0 or system < 0:
    raise SystemExit("invalid generator CPU measurement")
resource = dict(generator_cpu_percent_one_core=round(100 * (user+system) / elapsed, 3),
                user_seconds=user, system_seconds=system, elapsed_seconds=elapsed,
                isolated_client_physical_core=True)
pathlib.Path(sys.argv[2]).write_text(json.dumps(resource, indent=2) + "\\n")
PY
  jq -e '.generator_cpu_percent_one_core >= 0 and .elapsed_seconds >= 12
    and .generator_cpu_percent_one_core < 85' "$output_dir/generator-$concurrency.json" >/dev/null || {
      echo "::error::No proven independent client CPU headroom at concurrency $concurrency." >&2
      exit 1
    }
  python3 - "$server_pid" "$output_dir/fds-$concurrency.json" <<'PY'
import json
import pathlib
import sys
root = int(sys.argv[1])
todo = [root]
seen = set()
fds = 0
while todo:
    pid = todo.pop()
    if pid in seen:
        continue
    seen.add(pid)
    proc = pathlib.Path("/proc") / str(pid)
    if not proc.is_dir():
        continue
    fds += len(list((proc / "fd").glob("*")))
    children = proc / "task" / str(pid) / "children"
    if children.is_file():
        todo.extend(int(c) for c in children.read_text().split())
if fds <= 0:
    raise SystemExit("missing live supervisor and child FD samples")
pathlib.Path(sys.argv[2]).write_text(json.dumps(
    {"server_process_tree_sampled_fds": fds, "sampled_pids": len(seen)}
) + "\\n")
PY
  jq -e --argjson requested "$concurrency" \
    '.correctness_passed == true and .concurrency == $requested and
     .successful_requests == .requests_total and .successful_requests > 0 and
     .errors_total == 0 and .timeouts_total == 0 and .validation_failures == 0 and
     .throughput_rps > 0 and .latency_ms.p99 > 0 and
     .rss_peak_bytes > 0 and .cpu_percent >= 0' "$output" >/dev/null
done

jq -s '{headroom_verified:all(.[]; .generator_cpu_percent_one_core < 85),
  samples: .}' "$output_dir"/generator-{1,4,16,32,64}.json > "$output_dir/loadgen-headroom.json"

jq -s --arg sha "$sha" --slurpfile affinity "$output_dir/affinity.json" \
  --slurpfile headroom "$output_dir/loadgen-headroom.json" \
  '{schema:1, source_sha:$sha, release_certified:false,
    generator_headroom_verified:$headroom[0].headroom_verified,
    generator_cpu_percent_samples:$headroom[0].samples,
    methodology:"isolated distinct physical cores, 3s warmup and 12s measured per concurrency",
    affinity:$affinity[0], samples:[.[] | {
      concurrency, throughput_rps, latency_ms, cpu_percent, rss_peak_bytes,
      successful_requests, requests_total, correctness_passed, errors_total, timeouts_total
    }]}' \
  "$output_dir"/h1-concurrency-{1,4,16,32,64}.json > "$output_dir/concurrency-summary.json"

jq -e '.release_certified == false and .generator_headroom_verified == true and
  (.source_sha | test("^[0-9a-f]{40}$")) and
  (.samples | length) == 5 and (.generator_cpu_percent_samples | length) == 5 and
  ([.samples[] | select(.correctness_passed != true or .errors_total != 0 or
    .timeouts_total != 0)] | length) == 0' "$output_dir/concurrency-summary.json" >/dev/null
