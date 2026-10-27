local children = {}
function setup(thread)
  table.insert(children, thread)
end
function init(args)
  failed = 0
end
function response(status, headers, body)
  local native = body == "ok"
  local hosted = body:find('"method":"GET"', 1, true)
    and body:find('"target":"/rr?case=bench"', 1, true)
    and body:find('"pool_roundtrip":true', 1, true)
    and body:find('"socket_roundtrip":true', 1, true)
    and body:find('"file_roundtrip":true', 1, true)
  if status ~= 200 or not (native or hosted) then
    failed = failed + 1
  end
end
function done(summary, latency, requests)
  local failures = 0
  for _, child in ipairs(children) do
    failures = failures + (child:get("failed") or 0)
  end
  local e = summary.errors
  local net_errors = e.connect + e.read + e.write + e.status + e.timeout
  local success = summary.requests - failures
  io.write(string.format(
    'RUNWIRE_METRIC:{"requests_total":%d,"successful_requests":%d,"validation_failures":%d,"errors_total":%d,"timeouts_total":%d,"throughput_rps":%.3f,"latency_ms":{"p50":%.3f,"p95":%.3f,"p99":%.3f},"correctness_passed":%s}\n',
    summary.requests, success, failures, net_errors, e.timeout,
    summary.requests / summary.duration * 1000000,
    latency:percentile(50)/1000, latency:percentile(95)/1000,
    latency:percentile(99)/1000,
    (summary.requests > 0 and failures == 0 and net_errors == 0) and "true" or "false"
  ))
end
